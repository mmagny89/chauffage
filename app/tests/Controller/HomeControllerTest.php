<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\HeatingStart;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use App\Enum\DaySlot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class HomeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-10-09 14:00:00', 'Europe/Paris'));
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testWithoutReadingsTheDashboardInvitesToTheFirstOne(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorTextContains('main', 'Lyon (Rhône, France)');
        self::assertSelectorTextContains('main', 'Aucun relevé pour l’instant');
        self::assertSelectorExists('[role=progressbar][aria-valuenow="0"][aria-valuemax="5"]');
        self::assertCount(1, $crawler->filter('main a[href="/releves"]:contains("Saisir un relevé")'));
        self::assertSelectorNotExists('a:contains("Voir les recommandations")');
        self::assertSelectorNotExists('#a-venir');
    }

    public function testProgressAndUpcomingSlotsOnceReadingsExist(): void
    {
        $user = $this->createUser('a@example.com');
        $this->addReadings($user, ['2026-10-07', '2026-10-08']);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('[role=progressbar][aria-valuenow="2"][aria-valuetext="2 jours de relevés sur 5"]');
        self::assertSelectorTextContains('main', '2 jours de relevés sur 5 : encore 3');
        self::assertCount(2, $crawler->filter('#a-venir + ul li'), 'Les deux prochains créneaux.');
        self::assertStringContainsString('Après-midi · ven. 9 oct.', $crawler->filter('#a-venir + ul li')->first()->text());
        self::assertSelectorExists('main a[href="/recommandations"]');
    }

    public function testRoomsAppearOnTheDashboardOnlyWhenTheyHaveTheirOwnTargets(): void
    {
        $user = $this->createUser('a@example.com');
        $this->addReadings($user, ['2026-10-07', '2026-10-08']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('#par-piece', 'Aucune température propre : pas de recommandation par pièce.');
        self::assertSelectorTextContains('#a-venir', 'À venir');

        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $salon = $this->em->getRepository(Place::class)->findOneBy(['household' => $household, 'name' => 'Salon']) ?? throw new \LogicException('Salon introuvable.');
        $cave = new Place($household, 'Cave');
        $this->em->persist($cave);
        $salon->setTarget(DaySlot::Afternoon, 21.0);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        $rooms = $crawler->filter('#par-piece + ul > li');
        self::assertCount(2, $rooms, 'Le salon a une température propre : la cave figure aussi.');
        self::assertStringContainsString('Cave', $rooms->first()->filter('h3')->text());
        self::assertStringContainsString('Salon', $rooms->eq(1)->filter('h3')->text());
        self::assertCount(2, $rooms->first()->filter('ul li'), 'Les deux prochains créneaux.');
        self::assertSelectorExists('#par-piece + ul h3 svg[aria-hidden=true]');
        self::assertSelectorTextContains('#a-venir', 'Tout le foyer');
    }

    public function testCalibratedHouseholdIsToldSo(): void
    {
        $user = $this->createUser('a@example.com');
        $this->addReadings($user, ['2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('main', '5 jours de relevés : le calibrage est possible');
        self::assertSelectorExists('[role=progressbar][aria-valuenow="5"]');
    }

    public function testAForecastOutageDoesNotBreakTheDashboard(): void
    {
        $user = $this->createUser('a@example.com', latitude: 85.0);
        $this->addReadings($user, ['2026-10-08']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorNotExists('#a-venir');
    }

    public function testShutterAdviceDoesNotNeedReadings(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/');

        // Le double donne un premier jour ensoleillé (lever 7 h 30, coucher 18 h) puis un jour couvert, à 5 °C.
        self::assertSelectorTextContains('#volets', 'Volets');
        self::assertSelectorTextContains('#volets + ul li:nth-child(1)', 'Soleil prévu (8 h) : ouvrez à 7 h 30');
        self::assertSelectorTextContains('#volets + ul li:nth-child(1)', 'coucher du soleil, à 18 h 00');
        self::assertSelectorTextContains('#volets + ul li:nth-child(2)', 'Peu de soleil à récupérer');
    }

    public function testAForecastOutageHidesShutterAdviceOnly(): void
    {
        $this->client->loginUser($this->createUser('a@example.com', latitude: 85.0));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#volets');
    }

    public function testNoFrostBannerWhenItStaysMild(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('[aria-labelledby=froid]');
    }

    public function testFrostBannerWhenColdIsForecast(): void
    {
        // Le double baisse toutes les températures de 20 °C pour une longitude > 170 : minimum -20 °C.
        $this->client->loginUser($this->createUser('a@example.com', longitude: 175.0));

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('[aria-labelledby=froid]', 'Grand froid annoncé');
        self::assertSelectorTextContains('[aria-labelledby=froid]', 'Jusqu’à -20,0 °C');
    }

    public function testAForecastOutageHidesTheFrostBanner(): void
    {
        $this->client->loginUser($this->createUser('a@example.com', latitude: 85.0));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[aria-labelledby=froid]');
    }

    public function testNoReminderWithoutReadings(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('[aria-labelledby=rappel]');
    }

    public function testReminderToCompleteTheCalibration(): void
    {
        $user = $this->createUser('a@example.com');
        // Relevés de 0 à 23 °C : la plage couvre toutes les prévisions du double, seul le calibrage manque.
        $this->addReadings($user, ['2026-10-07', '2026-10-08'], [0.0, 23.0]);
        $this->client->loginUser($user);

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('[aria-labelledby=rappel]', 'Il manque encore 3 jours de relevés');
        self::assertSelectorExists('[aria-labelledby=rappel] a[href="/releves"]');
    }

    public function testReminderWhenTheForecastLeavesTheMeasuredRange(): void
    {
        // Relevés à 5 °C ; le double annonce de 0 à 23 °C : 23 °C sort de la plage (2 à 8 °C avec la marge).
        $user = $this->createUser('a@example.com');
        $this->addReadings($user, ['2026-10-07', '2026-10-08', '2026-10-09']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('[aria-labelledby=rappel]', 'sont annoncés dehors');
        self::assertSelectorTextContains('[aria-labelledby=rappel]', 'vos relevés vont de 5,0 à 5,0 °C');
    }

    public function testUpcomingSlotsShowTheWarmUpTimeOnceTheHeatingRateIsKnown(): void
    {
        $user = $this->createUser('a@example.com');
        $this->addReadings($user, ['2026-10-07', '2026-10-08'], [0.0, 23.0]);
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $salon = $this->em->getRepository(Place::class)->findOneBy(['household' => $household, 'name' => 'Salon']) ?? throw new \LogicException('Salon introuvable.');
        foreach (['2026-10-01' => 60, '2026-10-02' => 60] as $day => $minutes) {
            $start = new HeatingStart($salon, new \DateTimeImmutable($day.' 07:00'), 20.0, 18.0, 5.0);
            $start->markReached((new \DateTimeImmutable($day.' 07:00'))->modify(\sprintf('+%d minutes', $minutes)));
            $this->em->persist($start);
        }
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/');

        // Vitesse : 2 °C par heure. Après-midi à 14,5 °C dehors, intérieur estimé sous la cible : « pour y arriver ».
        self::assertSelectorTextContains('#a-venir + ul', 'pour y arriver');
    }

    public function testQuickLinksCoverEveryArea(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $crawler = $this->client->request('GET', '/');

        $links = $crawler->filter('#acces + ul a')->each(static fn ($a) => (string) $a->attr('href'));
        self::assertSame(['/releves', '/recommandations', '/ecarts', '/previsions', '/fiabilite'], $links);
    }

    private function createUser(string $email, float $latitude = 45.75, float $longitude = 4.85): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setVerified(true);
        $household = (new Household($user))->completeSetup(new \DateTimeImmutable('2026-01-01'));
        $household->locate('Lyon (Rhône, France)', $latitude, $longitude, 'Europe/Paris');
        $this->em->persist($user);
        $this->em->persist($household);
        $this->em->flush();

        return $user;
    }

    /**
     * @param list<string> $days
     * @param list<float>  $outdoors température extérieure de chaque relevé, dans l'ordre des jours
     */
    private function addReadings(User $user, array $days, array $outdoors = []): void
    {
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $place = new Place($household, 'Salon');
        $this->em->persist($place);
        foreach ($days as $i => $day) {
            $this->em->persist(new Reading($place, new \DateTimeImmutable($day.' 08:00'), $outdoors[$i] ?? 5.0, 15.0));
        }
        $this->em->flush();
    }
}
