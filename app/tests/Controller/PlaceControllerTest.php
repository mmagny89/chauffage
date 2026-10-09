<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use App\Service\PlaceManager;
use App\Service\RoomCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PlaceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('POST', '/reglages/lieux', ['choice' => 'Salon']);

        self::assertResponseRedirects('/login');
    }

    public function testSettingsListsPlacesAndOffersOnlyUndeclaredRooms(): void
    {
        $user = $this->createUser('a@example.com');
        $this->createPlace($user, 'Salon');
        $this->createPlace($user, 'Atelier');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/reglages');

        self::assertSame(['Atelier', 'Salon'], $crawler->filter('#lieux ul input[name=name]')->each(static fn ($i) => (string) $i->attr('value')));
        $options = $crawler->filter('#nouveau-lieu optgroup[label=Pièces] option')->each(static fn ($o) => $o->text());
        self::assertNotContains('Salon', $options, 'Un lieu déjà déclaré n’est plus proposé.');
        self::assertContains('Cuisine', $options);
        self::assertSelectorExists('#nouveau-lieu optgroup[label=Autre] option[value="__other__"]');
    }

    public function testAddsARoomFromTheList(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $this->postAdd(['choice' => 'Cuisine']);

        self::assertResponseRedirects('/reglages#lieux');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', 'Lieu ajouté : Cuisine.');
        self::assertSame(['Cuisine'], $this->placeNames($user));
    }

    public function testAddsACustomPlaceWithNormalizedName(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $this->postAdd(['choice' => RoomCatalog::OTHER, 'custom' => '  Chambre   de  Léa ']);

        self::assertSame(['Chambre de Léa'], $this->placeNames($user));
    }

    public function testRefusesADuplicateIgnoringCase(): void
    {
        $user = $this->createUser('a@example.com');
        $this->createPlace($user, 'Salon');
        $this->client->loginUser($user);

        $this->postAdd(['choice' => RoomCatalog::OTHER, 'custom' => 'SALON']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Ce lieu existe déjà');
        self::assertSame(['Salon'], $this->placeNames($user));
    }

    /**
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badNames')]
    public function testRefusesAnInvalidName(string $choice, string $custom, string $message): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $this->postAdd(['choice' => $choice, 'custom' => $custom]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', $message);
        self::assertSame([], $this->placeNames($user));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function badNames(): iterable
    {
        yield 'aucun choix' => ['', '', 'Indiquez le nom du lieu.'];
        yield 'autre sans nom' => [RoomCatalog::OTHER, '   ', 'Indiquez le nom du lieu.'];
        yield 'nom trop long' => [RoomCatalog::OTHER, str_repeat('é', 81), 'ne doit pas dépasser 80 caractères'];
    }

    public function testRefusesMoreThanTheMaximumNumberOfPlaces(): void
    {
        $user = $this->createUser('a@example.com');
        for ($i = 1; $i <= PlaceManager::MAX_PLACES; ++$i) {
            $this->createPlace($user, 'Lieu '.$i);
        }
        $this->client->loginUser($user);

        $this->postAdd(['choice' => 'Cuisine']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'ne peut pas compter plus de 30 lieux');
        self::assertCount(PlaceManager::MAX_PLACES, $this->placeNames($user));
    }

    public function testAddWithInvalidCsrfTokenIsRefused(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/reglages/lieux', ['choice' => 'Cuisine', '_token' => 'invalide']);

        self::assertResponseRedirects('/reglages#lieux');
        self::assertSame([], $this->placeNames($user));
    }

    public function testRenamesAPlaceAndKeepsItsReadings(): void
    {
        $user = $this->createUser('a@example.com');
        $place = $this->createPlace($user, 'Salon');
        $this->em->persist(new Reading($place, new \DateTimeImmutable('2026-10-08 07:30'), 5.0, 18.0));
        $this->em->flush();
        $this->client->loginUser($user);

        $this->submitRow($place, 'Renommer', ['name' => 'Séjour']);

        self::assertResponseRedirects('/reglages#lieux');
        self::assertSame(['Séjour'], $this->placeNames($user));
        self::assertSame(1, $this->em->getRepository(Reading::class)->count([]));
    }

    public function testRenameToAnExistingNameIsRefused(): void
    {
        $user = $this->createUser('a@example.com');
        $salon = $this->createPlace($user, 'Salon');
        $this->createPlace($user, 'Cave');
        $this->client->loginUser($user);

        $this->submitRow($salon, 'Renommer', ['name' => 'cave']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Ce lieu existe déjà');
        self::assertSame(['Cave', 'Salon'], $this->placeNames($user));
    }

    public function testRenamingToTheSameNameWithADifferentCaseIsAllowed(): void
    {
        $user = $this->createUser('a@example.com');
        $salon = $this->createPlace($user, 'salon');
        $this->client->loginUser($user);

        $this->submitRow($salon, 'Renommer', ['name' => 'Salon']);

        self::assertSame(['Salon'], $this->placeNames($user));
    }

    public function testDeletingAPlaceDeletesItsReadings(): void
    {
        $user = $this->createUser('a@example.com');
        $salon = $this->createPlace($user, 'Salon');
        $cave = $this->createPlace($user, 'Cave');
        $this->em->persist(new Reading($salon, new \DateTimeImmutable('2026-10-08 07:30'), 5.0, 18.0));
        $this->em->persist(new Reading($cave, new \DateTimeImmutable('2026-10-08 07:30'), 5.0, 12.0));
        $this->em->flush();
        $this->client->loginUser($user);

        $this->submitRow($salon, 'Supprimer');

        self::assertResponseRedirects('/reglages#lieux');
        self::assertSame(['Cave'], $this->placeNames($user));
        $this->em->clear();
        $remaining = $this->em->getRepository(Reading::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('Cave', $remaining[0]->getPlace()->getName());
    }

    public function testSomeoneElseCannotRenameOrDelete(): void
    {
        $owner = $this->createUser('a@example.com');
        $place = $this->createPlace($owner, 'Salon');
        $id = $place->getId();
        $this->client->loginUser($this->createUser('b@example.com'));

        $this->client->request('POST', '/reglages/lieux/'.$id.'/renommer', ['name' => 'Pirate', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/reglages/lieux/'.$id.'/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(['Salon'], $this->placeNames($owner));
    }

    public function testRenameAndDeleteWithInvalidCsrfTokenAreRefused(): void
    {
        $user = $this->createUser('a@example.com');
        $place = $this->createPlace($user, 'Salon');
        $this->client->loginUser($user);

        $this->client->request('POST', '/reglages/lieux/'.$place->getId().'/renommer', ['name' => 'Pirate', '_token' => 'invalide']);
        self::assertResponseRedirects('/reglages#lieux');
        $this->client->request('POST', '/reglages/lieux/'.$place->getId().'/supprimer', ['_token' => 'invalide']);
        self::assertResponseRedirects('/reglages#lieux');

        self::assertSame(['Salon'], $this->placeNames($user));
    }

    /**
     * @param array<string, string> $fields
     */
    private function postAdd(array $fields): void
    {
        $crawler = $this->client->request('GET', '/reglages');
        $token = (string) $crawler->filter('form[action="/reglages/lieux"] input[name=_token]')->attr('value');

        $this->client->request('POST', '/reglages/lieux', $fields + ['_token' => $token]);
    }

    /**
     * @param array<string, string> $values
     */
    private function submitRow(Place $place, string $button, array $values = []): void
    {
        $crawler = $this->client->request('GET', '/reglages');
        $form = $this->rowForm($crawler, $place, $button);
        $this->client->submit($form, $values);
    }

    private function rowForm(Crawler $crawler, Place $place, string $button): \Symfony\Component\DomCrawler\Form
    {
        $action = '/reglages/lieux/'.$place->getId().('Renommer' === $button ? '/renommer' : '/supprimer');

        return $crawler->filter(\sprintf('form[action="%s"]', $action))->form();
    }

    /**
     * @return list<string>
     */
    private function placeNames(User $user): array
    {
        $this->em->clear();
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]);
        $names = array_map(static fn (Place $p): string => $p->getName(), $this->em->getRepository(Place::class)->findBy(['household' => $household]));
        sort($names);

        return $names;
    }

    private function createPlace(User $user, string $name): Place
    {
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]);
        $place = new Place($household, $name);
        $this->em->persist($place);
        $this->em->flush();

        return $place;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setVerified(true);
        $this->em->persist($user);
        $this->em->persist(new Household($user));
        $this->em->flush();

        return $user;
    }
}
