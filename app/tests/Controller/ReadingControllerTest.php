<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
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

    public function testTellsToTakeReadingsWithTheHeatingOff(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/releves');

        self::assertSelectorTextContains('aside[role=note] h2', 'chauffage éteint');
        self::assertSelectorTextContains('aside[role=note]', 'sans chauffage');
    }

    public function testWithoutDeclaredPlacesTheHouseholdIsSentToSettings(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/releves');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Déclarez d’abord les lieux');
        self::assertSelectorExists('a[href="/reglages#lieux"]');
        self::assertSelectorNotExists('form[name=reading_session]');
    }

    public function testFormAsksForEveryDeclaredPlace(): void
    {
        $user = $this->createUser('a@example.com');
        $this->createPlaces($user, ['Salon', 'Cave', 'Écurie']);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/releves');

        $labels = $crawler->filter('fieldset label')->each(static fn ($label) => $label->text());
        self::assertSame(['Cave', 'Écurie', 'Salon'], $labels, 'Un champ par lieu, par ordre alphabétique.');
        self::assertCount(3, $crawler->filter('input[name^="reading_session[indoor]"][type=number]'));
        self::assertSelectorExists('input[name="reading_session[outdoor]"]');
        self::assertSelectorExists('input[name="reading_session[time]"]');
    }

    public function testRecordsOneReadingPerPlaceWithSharedTimeAndOutdoorTemperature(): void
    {
        $user = $this->createUser('a@example.com');
        $places = $this->createPlaces($user, ['Salon', 'Cave']);
        $this->client->loginUser($user);

        $this->submit('2026-10-08', '07:30', '4.5', ['Salon' => '18.0', 'Cave' => '12.5'], $places);

        self::assertResponseRedirects('/releves');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', '2 relevés enregistrés');

        $readings = $this->em->getRepository(Reading::class)->findAll();
        self::assertCount(2, $readings);
        foreach ($readings as $reading) {
            self::assertSame('2026-10-08 07:30', $reading->getMeasuredAt()->format('Y-m-d H:i'));
            self::assertSame(4.5, $reading->getOutdoorTemperature());
        }
        $byPlace = [];
        foreach ($readings as $reading) {
            $byPlace[$reading->getPlace()->getName()] = $reading->getIndoorTemperature();
        }
        self::assertSame(['Cave' => 12.5, 'Salon' => 18.0], $byPlace);
    }

    public function testEveryPlaceMustBeFilledAndNothingIsSavedOtherwise(): void
    {
        $user = $this->createUser('a@example.com');
        $places = $this->createPlaces($user, ['Salon', 'Cave']);
        $this->client->loginUser($user);

        $this->submit('2026-10-08', '07:30', '4.5', ['Salon' => '18.0', 'Cave' => ''], $places);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Indiquez la température de ce lieu.');
        self::assertSame(0, $this->em->getRepository(Reading::class)->count([]));
    }

    public function testPlacesAddedLaterAreAskedForToo(): void
    {
        $user = $this->createUser('a@example.com');
        $this->createPlaces($user, ['Salon']);
        $this->client->loginUser($user);
        $this->client->request('GET', '/releves');
        self::assertCount(1, $this->client->getCrawler()->filter('fieldset input[type=number]'));

        $this->createPlaces($user, ['Chambre parentale']);

        $this->client->request('GET', '/releves');
        self::assertCount(2, $this->client->getCrawler()->filter('fieldset input[type=number]'));
    }

    public function testExistingReadingAtTheSameInstantRejectsTheWholeSession(): void
    {
        $user = $this->createUser('a@example.com');
        $places = $this->createPlaces($user, ['Salon', 'Cave']);
        $this->client->loginUser($user);
        $this->submit('2026-10-08', '07:30', '4.5', ['Salon' => '18.0', 'Cave' => '12.5'], $places);

        $this->submit('2026-10-08', '07:30', '5.0', ['Salon' => '19.0', 'Cave' => '13.0'], $places);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un relevé existe déjà pour ce lieu à cette heure.');
        self::assertSame(2, $this->em->getRepository(Reading::class)->count([]));
    }

    public function testPartialClashRecordsNothing(): void
    {
        $user = $this->createUser('a@example.com');
        $places = $this->createPlaces($user, ['Salon']);
        $this->client->loginUser($user);
        $this->submit('2026-10-08', '07:30', '4.5', ['Salon' => '18.0'], $places);

        $more = $this->createPlaces($user, ['Cave']);
        $this->submit('2026-10-08', '07:30', '4.5', ['Salon' => '18.0', 'Cave' => '12.0'], [...$places, ...$more]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->em->getRepository(Reading::class)->count([]), 'La cave n’est pas enregistrée seule.');
    }

    public function testFutureInstantIsRefusedInTheHouseholdTimezone(): void
    {
        $user = $this->createUser('a@example.com');
        $places = $this->createPlaces($user, ['Salon']);
        $this->client->loginUser($user);

        // Il est 14 h à Paris : 14 h 30 aujourd'hui est dans le futur, 13 h 59 non.
        $this->submit('2026-10-09', '14:30', '12.0', ['Salon' => '19.0'], $places);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Cette heure est dans le futur.');

        $this->submit('2026-10-09', '13:59', '12.0', ['Salon' => '19.0'], $places);
        self::assertResponseRedirects('/releves');
    }

    public function testImplausibleTemperaturesAreRefused(): void
    {
        $user = $this->createUser('a@example.com');
        $places = $this->createPlaces($user, ['Salon']);
        $this->client->loginUser($user);

        $this->submit('2026-10-08', '07:30', '99', ['Salon' => '18.0'], $places);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La température extérieure doit être comprise entre');

        $this->submit('2026-10-08', '07:30', '5', ['Salon' => '80'], $places);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La température intérieure doit être comprise entre');
        self::assertSame(0, $this->em->getRepository(Reading::class)->count([]));
    }

    public function testListShowsReadingsWithDeltaAndSlot(): void
    {
        $user = $this->createUser('a@example.com');
        $places = $this->createPlaces($user, ['Salon']);
        $this->client->loginUser($user);
        $this->submit('2026-10-08', '07:30', '4.5', ['Salon' => '18.0'], $places);

        $crawler = $this->client->request('GET', '/releves');

        $row = $crawler->filter('tbody tr')->first()->text();
        self::assertStringContainsString('Salon', $row);
        self::assertStringContainsString('Matin', $row);
        self::assertStringContainsString('+13,5 °C', $row);
        self::assertSelectorTextContains('caption', 'Jeudi 8 octobre 2026');
    }

    public function testProgressCountsDistinctDays(): void
    {
        $user = $this->createUser('a@example.com');
        $places = $this->createPlaces($user, ['Salon']);
        $this->client->loginUser($user);
        $this->submit('2026-10-07', '07:30', '4.0', ['Salon' => '18.0'], $places);
        $this->submit('2026-10-07', '19:00', '6.0', ['Salon' => '19.0'], $places);
        $this->submit('2026-10-08', '07:30', '4.5', ['Salon' => '18.0'], $places);

        $this->client->request('GET', '/releves');

        self::assertSelectorTextContains('[role=progressbar] + p', '2 jours sur 5');
        self::assertSelectorExists('[role=progressbar][aria-valuenow="2"][aria-valuemax="5"]');
    }

    public function testOwnerCanDeleteAReading(): void
    {
        $user = $this->createUser('a@example.com');
        $this->createReading($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/releves');
        $this->client->submit($crawler->filter('tbody form')->form());

        self::assertResponseRedirects('/releves');
        self::assertSame(0, $this->em->getRepository(Reading::class)->count([]));
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

    public function testEachHouseholdOnlySeesItsOwnPlacesAndReadings(): void
    {
        $this->createReading($this->createUser('a@example.com'));
        $this->client->loginUser($this->createUser('b@example.com'));

        $this->client->request('GET', '/releves');

        self::assertSelectorNotExists('tbody tr');
        self::assertSelectorTextContains('body', 'Aucun relevé pour le moment.');
        self::assertSelectorTextContains('body', 'Déclarez d’abord les lieux');
    }

    /**
     * @param array<string, string> $indoor température intérieure par nom de lieu
     * @param list<Place>           $places
     */
    private function submit(string $date, string $time, string $outdoor, array $indoor, array $places): void
    {
        $crawler = $this->client->request('GET', '/releves');
        $form = $crawler->selectButton('Enregistrer le relevé')->form();
        $values = $form->getPhpValues();
        $values['reading_session']['date'] = $date;
        $values['reading_session']['time'] = $time;
        $values['reading_session']['outdoor'] = $outdoor;
        foreach ($places as $place) {
            $values['reading_session']['indoor']['p'.$place->getId()] = $indoor[$place->getName()] ?? '';
        }

        $this->client->request('POST', $form->getUri(), $values);
    }

    /**
     * @param list<string> $names
     *
     * @return list<Place>
     */
    private function createPlaces(User $user, array $names): array
    {
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]);
        $places = [];
        foreach ($names as $name) {
            $place = new Place($household, $name);
            $this->em->persist($place);
            $places[] = $place;
        }
        $this->em->flush();

        return $places;
    }

    private function createReading(User $user): Reading
    {
        [$place] = $this->createPlaces($user, ['Salon']);
        $reading = new Reading($place, new \DateTimeImmutable('2026-10-08 07:30'), 5.0, 18.0);
        $this->em->persist($reading);
        $this->em->flush();

        return $reading;
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
