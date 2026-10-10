<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\HeatingStart;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use App\Tests\Support\ResetsRateLimiters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class HeatingStartControllerTest extends WebTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-10-09 14:00:00', 'Europe/Paris'));
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::resetRateLimiters();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testReadingFormOffersAnOptionalSetpointPerPlace(): void
    {
        $user = $this->createUser('a@example.com');
        $this->createPlace($user, 'Salon');
        $this->createPlace($user, 'Cave');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/releves');

        self::assertSame(['Cave : consigne (°C)', 'Salon : consigne (°C)'], $crawler->filter('#allumage label')->each(static fn ($l) => $l->text()));
        self::assertCount(2, $crawler->filter('input[name^="reading_session[heating]"]'));
        self::assertSelectorNotExists('#chauffage', 'Aucun allumage : pas de tableau.');
    }

    public function testASetpointNotesTheHeatingStartWithTheReadingValues(): void
    {
        $user = $this->createUser('a@example.com');
        $salon = $this->createPlace($user, 'Salon');
        $cave = $this->createPlace($user, 'Cave');
        $this->client->loginUser($user);

        $this->submit(['date' => '2026-10-09', 'time' => '07:30', 'outdoor' => '6.5', 'indoor' => [[$salon, '16.4'], [$cave, '12.0']], 'heating' => [$salon, '20.5']]);

        self::assertResponseRedirects('/releves');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', 'Chauffage noté pour : Salon.');

        $starts = $this->em->getRepository(HeatingStart::class)->findAll();
        self::assertCount(1, $starts, 'Un allumage seulement : la cave n’a pas de consigne.');
        self::assertSame('Salon', $starts[0]->getPlace()->getName());
        self::assertSame('2026-10-09 07:30', $starts[0]->getStartedAt()->format('Y-m-d H:i'));
        self::assertSame(20.5, $starts[0]->getSetpoint());
        self::assertSame(16.4, $starts[0]->getIndoorTemperature(), 'Température du relevé du même lieu.');
        self::assertSame(6.5, $starts[0]->getOutdoorTemperature());
        self::assertSame(2, $this->em->getRepository(Reading::class)->count([]));

        $row = $crawler->filter('#chauffage tbody tr')->first()->text();
        self::assertStringContainsString('Salon', $row);
        self::assertStringContainsString('09/10/2026 à 07:30', $row);
        self::assertStringContainsString('20,5 °C', $row);
    }

    public function testWithoutSetpointNoHeatingStartIsNoted(): void
    {
        $user = $this->createUser('a@example.com');
        $salon = $this->createPlace($user, 'Salon');
        $this->client->loginUser($user);

        $this->submit(['date' => '2026-10-09', 'time' => '07:30', 'outdoor' => '6', 'indoor' => [[$salon, '16']]]);

        self::assertResponseRedirects('/releves');
        self::assertSame(1, $this->em->getRepository(Reading::class)->count([]));
        self::assertSame(0, $this->em->getRepository(HeatingStart::class)->count([]));
    }

    public function testImplausibleOrDuplicateSetpointRejectsEverything(): void
    {
        $user = $this->createUser('a@example.com');
        $salon = $this->createPlace($user, 'Salon');
        $this->em->persist(new HeatingStart($salon, new \DateTimeImmutable('2026-10-08 07:00'), 20.0, 16.0, 6.0));
        $this->em->flush();
        $this->client->loginUser($user);

        $this->submit(['date' => '2026-10-09', 'time' => '07:30', 'outdoor' => '6', 'indoor' => [[$salon, '16']], 'heating' => [$salon, '45']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#allumage', 'La consigne doit être comprise entre');

        // Un allumage existe déjà à cet instant : aucun relevé n'est écrit non plus.
        $this->submit(['date' => '2026-10-08', 'time' => '07:00', 'outdoor' => '6', 'indoor' => [[$salon, '16']], 'heating' => [$salon, '20']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#allumage', 'Un allumage est déjà noté');

        self::assertSame(0, $this->em->getRepository(Reading::class)->count([]));
        self::assertSame(1, $this->em->getRepository(HeatingStart::class)->count([]));
    }

    public function testOnlyTheOwnerCanDeleteAHeatingStart(): void
    {
        $owner = $this->createUser('a@example.com');
        $start = new HeatingStart($this->createPlace($owner, 'Salon'), new \DateTimeImmutable('2026-10-08 07:00'), 20.0, 16.0, 6.0);
        $this->em->persist($start);
        $this->em->flush();
        $id = $start->getId();

        $this->client->loginUser($this->createUser('b@example.com'));
        $this->client->request('POST', '/releves/chauffage/'.$id.'/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($owner);
        $this->client->request('POST', '/releves/chauffage/'.$id.'/supprimer', ['_token' => 'invalide']);
        self::assertResponseRedirects('/releves#chauffage');
        self::assertSame(1, $this->em->getRepository(HeatingStart::class)->count([]));

        $crawler = $this->client->request('GET', '/releves');
        $this->client->submit($crawler->filter('#chauffage tbody form[action$="/supprimer"]')->form());
        self::assertResponseRedirects('/releves#chauffage');
        self::assertSame(0, $this->em->getRepository(HeatingStart::class)->count([]));
    }

    public function testTheSetpointCanBeMarkedReachedAndTheDurationIsShown(): void
    {
        $user = $this->createUser('a@example.com');
        $start = new HeatingStart($this->createPlace($user, 'Salon'), new \DateTimeImmutable('2026-10-09 12:30'), 20.0, 16.0, 6.0);
        $this->em->persist($start);
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/releves');
        self::assertSelectorTextContains('#chauffage', 'Consigne atteinte');
        $this->client->submit($crawler->filter('#chauffage tbody form[action$="/atteinte"]')->form());

        self::assertResponseRedirects('/releves#chauffage');
        $this->em->clear();
        $fresh = $this->em->getRepository(HeatingStart::class)->find($start->getId());
        self::assertSame(90, $fresh?->getWarmUpMinutes(), 'Allumé à 12 h 30, atteint à 14 h (heure du double).');

        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', 'Consigne atteinte en 1 h 30');
        self::assertSelectorTextContains('#chauffage tbody', '14:00 (1 h 30)');
        self::assertSelectorNotExists('#chauffage tbody form[action$="/atteinte"]');
    }

    public function testReachedCannotBeMarkedTwiceByAnotherAccountOrWithABadToken(): void
    {
        $owner = $this->createUser('a@example.com');
        $start = new HeatingStart($this->createPlace($owner, 'Salon'), new \DateTimeImmutable('2026-10-09 12:30'), 20.0, 16.0, 6.0);
        $this->em->persist($start);
        $this->em->flush();
        $id = $start->getId();

        $this->client->loginUser($this->createUser('b@example.com'));
        $this->client->request('POST', '/releves/chauffage/'.$id.'/atteinte', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($owner);
        $this->client->request('POST', '/releves/chauffage/'.$id.'/atteinte', ['_token' => 'invalide']);
        self::assertResponseRedirects('/releves#chauffage');
        $this->em->clear();
        self::assertNull($this->em->getRepository(HeatingStart::class)->find($id)?->getReachedAt());

        $this->client->request('GET', '/releves/chauffage/'.$id.'/atteinte');
        self::assertResponseStatusCodeSame(405);
    }

    public function testASetpointTooLongAfterTheStartIsRefused(): void
    {
        $user = $this->createUser('a@example.com');
        $start = new HeatingStart($this->createPlace($user, 'Salon'), new \DateTimeImmutable('2026-10-07 07:00'), 20.0, 16.0, 6.0);
        $this->em->persist($start);
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/releves');
        $this->client->submit($crawler->filter('#chauffage tbody form[action$="/atteinte"]')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role=alert]', 'ce n’est plus une montée en température');
        $this->em->clear();
        self::assertNull($this->em->getRepository(HeatingStart::class)->find($start->getId())?->getReachedAt());
    }

    public function testDeletingAPlaceDeletesItsHeatingStarts(): void
    {
        $user = $this->createUser('a@example.com');
        $salon = $this->createPlace($user, 'Salon');
        $this->em->persist(new HeatingStart($salon, new \DateTimeImmutable('2026-10-08 07:00'), 20.0, 16.0, 6.0));
        $this->em->flush();

        $this->em->remove($salon);
        $this->em->flush();

        self::assertSame(0, $this->em->getRepository(HeatingStart::class)->count([]));
    }

    /**
     * @param array{date: string, time: string, outdoor: string, indoor: list<array{Place, string}>, heating?: array{Place, string}} $values
     */
    private function submit(array $values): void
    {
        $crawler = $this->client->request('GET', '/releves');
        $form = $crawler->selectButton('Enregistrer le relevé')->form();
        $post = $form->getPhpValues();
        foreach (['date', 'time', 'outdoor'] as $field) {
            $post['reading_session'][$field] = $values[$field];
        }
        foreach ($values['indoor'] as [$place, $temperature]) {
            $post['reading_session']['indoor']['p'.$place->getId()] = $temperature;
        }
        if (isset($values['heating'])) {
            $post['reading_session']['heating']['p'.$values['heating'][0]->getId()] = $values['heating'][1];
        }

        $this->client->request('POST', $form->getUri(), $post);
    }

    private function createPlace(User $user, string $name): Place
    {
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $place = new Place($household, $name);
        $this->em->persist($place);
        $this->em->flush();

        return $place;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setVerified(true);
        $this->em->persist($user);
        $this->em->persist((new Household($user))->completeSetup(new \DateTimeImmutable('2026-01-01')));
        $this->em->flush();

        return $user;
    }
}
