<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\RoomCatalog;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class ReadingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-10-09 14:00:00', 'Europe/Paris'));
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('test.cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/releves');

        self::assertResponseRedirects('/login');
    }

    public function testPageProvidesWhatTheCollectionControllerNeeds(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $crawler = $this->client->request('GET', '/releves');

        $holder = $crawler->filter('[data-controller=collection]');
        self::assertCount(1, $holder);
        self::assertSame('1', $holder->attr('data-collection-index-value'));
        $prototype = (string) $holder->attr('data-collection-prototype-value');
        self::assertStringContainsString('day_readings[rows][__name__][place]', $prototype);
        self::assertStringContainsString('data-controller="place"', $prototype);
        self::assertStringContainsString('data-collection-target="item"', $prototype);
        self::assertStringContainsString('data-action="collection#remove"', $prototype);
        self::assertSelectorExists('button[data-action="collection#add"]');
    }

    public function testRecordsADayWithSeveralPlacesAndReusesPlacesIgnoringCase(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $this->submit('2026-10-08', [
            ['Salon', '07:30', '4.5', '18.0'],
            ['Cuisine', '07:30', '4.5', '16.5'],
        ]);
        self::assertResponseRedirects('/releves');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', '2 relevés enregistrés');

        $this->submit('2026-10-08', [['@salon', '19:00', '9.0', '19.5']]);
        self::assertResponseRedirects('/releves');

        self::assertSame(3, $this->em->getRepository(Reading::class)->count([]));
        self::assertSame(2, $this->em->getRepository(Place::class)->count([]), '« salon » réutilise « Salon ».');
    }

    public function testListShowsReadingsWithDeltaAndSlot(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);
        $this->submit('2026-10-08', [['Salon', '07:30', '4.5', '18.0']]);

        $crawler = $this->client->request('GET', '/releves');

        $row = $crawler->filter('tbody tr')->first()->text();
        self::assertStringContainsString('Salon', $row);
        self::assertStringContainsString('Matin', $row);
        self::assertStringContainsString('+13,5 °C', $row);
        self::assertSelectorTextContains('caption', 'Jeudi 8 octobre 2026');
    }

    public function testExistingReadingIsRefused(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));
        $this->submit('2026-10-08', [['Salon', '07:30', '4.5', '18.0']]);

        $this->submit('2026-10-08', [['@SALON', '07:30', '5.0', '19.0']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un relevé existe déjà pour ce lieu à cette heure.');
        self::assertSame(1, $this->em->getRepository(Reading::class)->count([]));
    }

    public function testRejectsEverythingWhenOneRowIsRefused(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));
        $this->submit('2026-10-08', [['Salon', '07:30', '4.5', '18.0']]);

        $this->submit('2026-10-08', [
            ['Cuisine', '08:00', '5.0', '17.0'],
            ['Salon', '07:30', '5.0', '19.0'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->em->getRepository(Reading::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Place::class)->count([]));
    }

    public function testFutureInstantIsRefusedInTheHouseholdTimezone(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        // Il est 14 h à Paris : 14 h 30 aujourd'hui est dans le futur, 13 h 59 non.
        $this->submit('2026-10-09', [['Salon', '14:30', '12.0', '19.0']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Cette heure est dans le futur.');

        $this->submit('2026-10-09', [['Salon', '13:59', '12.0', '19.0']]);
        self::assertResponseRedirects('/releves');
    }

    public function testImplausibleTemperaturesAndDuplicateRowsAreRefused(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->submit('2026-10-08', [['Salon', '07:30', '99', '18.0']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La température extérieure doit être comprise entre');

        $this->submit('2026-10-08', [
            ['Salon', '07:30', '5.0', '18.0'],
            ['@salon', '07:30', '5.0', '18.0'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'apparaît déjà à la même heure');
        self::assertSame(0, $this->em->getRepository(Reading::class)->count([]));
    }

    public function testPlaceIsAGroupedDropdownOfRoomsAndOwnPlaces(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);
        $this->submit('2026-10-08', [['@Atelier', '07:30', '4.5', '16.0']]);

        $crawler = $this->client->request('GET', '/releves');

        $select = $crawler->filter('select[name="day_readings[rows][0][place]"]');
        self::assertCount(1, $select);
        self::assertGreaterThan(10, $select->filter('optgroup[label=Pièces] option')->count());
        self::assertSame('Cuisine', $select->filter('optgroup[label=Pièces] option')->eq(3)->text());
        self::assertSame(['Atelier'], $select->filter('optgroup[label="Vos autres lieux"] option')->each(static fn ($o) => $o->text()));
        self::assertSame('Autre lieu…', $select->filter('optgroup[label=Autre] option')->text());
    }

    public function testPlaceMustBeOneOfTheChoices(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->submit('2026-10-08', [['Salon de la reine', '07:30', '4.5', '18.0']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(Reading::class)->count([]));
    }

    public function testOtherPlaceNeedsAName(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->submit('2026-10-08', [['@', '07:30', '4.5', '18.0']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Indiquez le nom du lieu.');
    }

    public function testADayNeedsAtLeastOnePlace(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->submit('2026-10-08', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Renseignez au moins un lieu pour ce jour.');
    }

    public function testProgressCountsDistinctDays(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);
        $this->submit('2026-10-07', [['Salon', '07:30', '4.0', '18.0'], ['Salon', '19:00', '6.0', '19.0']]);
        self::assertResponseRedirects('/releves');
        self::assertSame(1, $this->em->getRepository(Place::class)->count([]), 'Un lieu nouveau cité deux fois dans le même envoi n’est créé qu’une fois.');
        $this->submit('2026-10-08', [['Salon', '07:30', '4.5', '18.0']]);

        $this->client->request('GET', '/releves');

        self::assertSelectorTextContains('[role=progressbar] + p', '2 jours sur 5');
        self::assertSelectorExists('[role=progressbar][aria-valuenow="2"][aria-valuemax="5"]');
    }

    public function testOwnerCanDeleteAReading(): void
    {
        $user = $this->createUser('a@example.com');
        $reading = $this->createReading($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/releves');
        $this->client->submit($crawler->filter('tbody form')->form());

        self::assertResponseRedirects('/releves');
        self::assertSame(0, $this->em->getRepository(Reading::class)->count([]));
        self::assertNotNull($reading);
    }

    public function testSomeoneElseCannotDeleteAReading(): void
    {
        $reading = $this->createReading($this->createUser('a@example.com'));
        $id = $reading->getId();
        $this->client->loginUser($this->createUser('b@example.com'));

        $this->client->request('POST', '/releves/'.$id.'/supprimer', ['_token' => 'n-importe-quoi']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->em->getRepository(Reading::class)->count([]));
    }

    public function testDeleteWithInvalidCsrfTokenIsRefused(): void
    {
        $user = $this->createUser('a@example.com');
        $reading = $this->createReading($user);
        $this->client->loginUser($user);

        $this->client->request('POST', '/releves/'.$reading->getId().'/supprimer', ['_token' => 'invalide']);

        self::assertResponseRedirects('/releves');
        self::assertSame(1, $this->em->getRepository(Reading::class)->count([]));
    }

    public function testEachHouseholdOnlySeesItsOwnReadings(): void
    {
        $this->createReading($this->createUser('a@example.com'));
        $this->client->loginUser($this->createUser('b@example.com'));

        $this->client->request('GET', '/releves');

        self::assertSelectorNotExists('tbody tr');
        self::assertSelectorTextContains('body', 'Aucun relevé pour le moment.');
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4?: string}> $rows lieu (de la liste, ou « @nom » pour un lieu libre), heure, extérieur, intérieur
     */
    private function submit(string $date, array $rows): void
    {
        $crawler = $this->client->request('GET', '/releves');
        $form = $crawler->selectButton('Enregistrer le jour')->form();
        $values = $form->getPhpValues();
        $values['day_readings']['date'] = $date;
        $values['day_readings']['rows'] = [];
        foreach ($rows as $index => [$place, $time, $outdoor, $indoor]) {
            $custom = '';
            if (str_starts_with($place, '@')) {
                $custom = substr($place, 1);
                $place = RoomCatalog::OTHER;
            }
            $values['day_readings']['rows'][$index] = ['place' => $place, 'customPlace' => $custom, 'time' => $time, 'outdoor' => $outdoor, 'indoor' => $indoor];
        }

        $this->client->request('POST', $form->getUri(), $values);
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setVerified(true);
        $this->em->persist($user);
        $this->em->persist(new Household($user));
        $this->em->flush();

        return $user;
    }

    private function createReading(User $user): Reading
    {
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]);
        $place = new Place($household, 'Salon');
        $reading = new Reading($place, new \DateTimeImmutable('2026-10-08 07:30'), 5.0, 18.0);
        $this->em->persist($place);
        $this->em->persist($reading);
        $this->em->flush();

        return $reading;
    }
}
