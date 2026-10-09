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

final class RecommendationControllerTest extends WebTestCase
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

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/recommandations');

        self::assertResponseRedirects('/login');
    }

    public function testMissingCityAndReadingsAreListed(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/recommandations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Il manque deux choses');
        self::assertSelectorExists('a[href="/reglages"]');
        self::assertSelectorExists('a[href="/releves"]');
        self::assertSelectorNotExists('table');
    }

    public function testReadingsAreStillMissingOnceTheCityIsSet(): void
    {
        $this->client->loginUser($this->createUser('a@example.com', located: true));

        $this->client->request('GET', '/recommandations');

        self::assertSelectorTextContains('body', 'Ville renseignée');
        self::assertSelectorExists('a[href="/releves"]');
        self::assertSelectorNotExists('table');
    }

    public function testShowsRecommendationsPerDayAndSlot(): void
    {
        // Relevés à 5 °C, écart +10 partout (un jour : pente typique −0,4) ; dehors (double de test) :
        // matin 8,5 / après-midi 14,5 / soirée 19,5 / nuit 7,5 → écart 8,6 / 6,2 / 4,2 / 9,0, soit dedans
        // 17,1 / 20,7 / 23,7 / 16,5 ; cibles 19 / 19 / 20 / 17.
        $user = $this->createUser('a@example.com', located: true);
        $this->addReadingsEverySlot($user, ['2026-10-08']);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations');

        self::assertResponseIsSuccessful();
        $headers = $crawler->filter('thead th')->each(static fn ($th) => $th->text());
        self::assertSame(['Jour', 'Matin', 'Après-midi', 'Soirée', 'Nuit'], $headers);

        $rows = $crawler->filter('tbody tr');
        self::assertCount(15, $rows);
        $cells = $rows->first()->filter('td');
        self::assertStringContainsString('Chauffer à 19,0 °C', $cells->eq(0)->text());
        self::assertStringContainsString('dedans ≈ 17 °C', $cells->eq(0)->text());
        self::assertStringContainsString('Couper', $cells->eq(1)->text());
        self::assertStringContainsString('dehors 14,5 °C', $cells->eq(1)->text());
        self::assertStringContainsString('dedans ≈ 21 °C', $cells->eq(1)->text());
        self::assertStringContainsString('Couper', $cells->eq(2)->text());
        self::assertStringContainsString('dedans ≈ 24 °C', $cells->eq(2)->text(), 'Pas 29,5 : l’écart se réduit quand il fait doux.');
        self::assertStringContainsString('Chauffer à 17,0 °C', $cells->eq(3)->text(), 'Nuit : 16,5 sous la cible de 17.');
    }

    public function testUpcomingSlotsUseTheWordingOfTheBrief(): void
    {
        $user = $this->createUser('a@example.com', located: true);
        $this->addReadingsEverySlot($user, ['2026-10-08']);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations');

        $upcoming = $crawler->filter('#a-venir + ul li');
        self::assertCount(4, $upcoming);
        // Il est 14 h : l'après-midi est en cours, puis soirée, nuit, matin suivant.
        self::assertStringContainsString('Après-midi · ven. 9 oct.', $upcoming->eq(0)->text());
        self::assertStringContainsString('permettent de couper le chauffage', $upcoming->eq(0)->text());
        self::assertStringContainsString('Nuit · ven. 9 oct.', $upcoming->eq(2)->text());
        self::assertStringContainsString('Matin · sam. 10 oct.', $upcoming->eq(3)->text());
        self::assertStringContainsString('Attention, les températures sont de 8,5 °C : il faut mettre le chauffage à 19,0 °C.', $upcoming->eq(3)->text());
    }

    public function testDistantDaysAreGreyedOut(): void
    {
        $user = $this->createUser('a@example.com', located: true);
        $this->addReadingsEverySlot($user, ['2026-10-08']);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations');

        $rows = $crawler->filter('tbody tr');
        self::assertStringNotContainsString('text-slate-500', (string) $rows->eq(6)->attr('class'));
        self::assertStringContainsString('text-slate-500', (string) $rows->eq(7)->attr('class'));
    }

    public function testResultsAreProvisionalBeforeFiveDays(): void
    {
        $user = $this->createUser('a@example.com', located: true);
        $this->addReadingsEverySlot($user, ['2026-10-07', '2026-10-08']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/recommandations');

        self::assertSelectorTextContains('[role=status]', 'Recommandations provisoires : 2 jours de relevés sur 5');
    }

    public function testNoProvisionalNoticeOnceFiveDaysAreEntered(): void
    {
        $user = $this->createUser('a@example.com', located: true);
        $this->addReadingsEverySlot($user, ['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/recommandations');

        self::assertSelectorNotExists('[role=status]');
    }

    public function testUsesTheTargetsOfTheHousehold(): void
    {
        $user = $this->createUser('a@example.com', located: true);
        $this->addReadingsEverySlot($user, ['2026-10-08']);
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $household->targetFor(\App\Enum\Weekday::Friday, \App\Enum\DaySlot::Morning)->setTemperature(17.0); // vendredi 9 : 17,1 ≥ 17, on coupe
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations');

        self::assertStringContainsString('Couper', $crawler->filter('tbody tr')->first()->filter('td')->eq(0)->text());
    }

    public function testTargetsDifferPerWeekdayAndAreShownInEachCell(): void
    {
        $user = $this->createUser('a@example.com', located: true);
        $this->addReadingsEverySlot($user, ['2026-10-08']);
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $household->targetFor(\App\Enum\Weekday::Saturday, \App\Enum\DaySlot::Morning)->setTemperature(17.0);
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations');

        $rows = $crawler->filter('tbody tr');
        $friday = $rows->eq(0)->filter('td')->eq(0)->text();   // matin, estimé 17,1
        $saturday = $rows->eq(1)->filter('td')->eq(0)->text();
        self::assertStringContainsString('ven. 9 oct.', $rows->eq(0)->text());
        self::assertStringContainsString('sam. 10 oct.', $rows->eq(1)->text());
        self::assertStringContainsString('Chauffer à 19,0 °C', $friday);
        self::assertStringContainsString('visé 19,0 °C', $friday);
        self::assertStringContainsString('Couper', $saturday, 'Samedi la cible est 17 : 17,1 suffit.');
        self::assertStringContainsString('visé 17,0 °C', $saturday);
    }

    public function testRegressionDrivesTheEstimateAndOutOfRangeForecastsAreFlagged(): void
    {
        // Matin, cinq jours à 2…14 °C : écart = 8 − 0,4 × extérieur. Prévisions du double de test :
        // matin 8,5 / après-midi 14,5 / soirée 19,5 / nuit 7,5 (les trois derniers : modèle général, même droite).
        $user = $this->createUser('a@example.com', located: true);
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $place = new Place($household, 'Salon');
        $this->em->persist($place);
        foreach ([[1, 2.0], [2, 5.0], [3, 8.0], [4, 11.0], [5, 14.0]] as [$day, $outdoor]) {
            $this->em->persist(new Reading($place, new \DateTimeImmutable(\sprintf('2026-10-%02d 08:00', $day)), $outdoor, round(8.0 + 0.6 * $outdoor, 1)));
        }
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations');

        $cells = $crawler->filter('tbody tr')->first()->filter('td');
        // Matin : 8,5 + (8 − 0,4 × 8,5 = 4,6) = 13,1, affiché à l'unité
        self::assertStringContainsString('dedans ≈ 13 °C', $cells->eq(0)->text());
        self::assertStringNotContainsString('hors plage mesurée', $cells->eq(0)->text());
        // Après-midi : 14,5 reste dans la marge (14 + 3) ; soirée : 19,5 la dépasse.
        self::assertStringNotContainsString('hors plage mesurée', $cells->eq(1)->text());
        self::assertStringContainsString('hors plage mesurée', $cells->eq(2)->text());
        self::assertStringContainsString('écart général', $cells->eq(2)->text());
        self::assertSelectorExists('a[href="/ecarts#modele"]');
    }

    public function testEachRoomHasItsOwnRecommendationNextToTheHouseholdOne(): void
    {
        // Relevés à 5 °C : le salon (isolé) à 15 °C (écart +10), la cave à 8 °C (écart +3). Après-midi prévu à 14,5 °C :
        // salon 14,5 + (10 − 0,4 × 9,5) = 20,7 ≥ 19 → couper ; cave 14,5 + (3 − 3,8) = 13,7 → chauffer ;
        // foyer (écart moyen 6,5) : 17,2 → chauffer, ce qui masque que le salon n'en a pas besoin.
        $user = $this->createUser('a@example.com', located: true);
        $this->addPlaceReadings($user, ['Salon' => 15.0, 'Cave' => 8.0]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations');

        $global = $crawler->filter('#a-venir + ul li')->eq(0)->text();
        self::assertStringContainsString('Après-midi · ven. 9 oct.', $global);
        self::assertStringContainsString('il faut mettre le chauffage', $global, 'Le foyer, en moyenne, chauffe.');

        $matrix = $crawler->filter('#par-piece + div table');
        self::assertCount(1, $matrix);
        self::assertSame(['Pièce', 'Après-midi · ven. 9 oct.', 'Soirée · ven. 9 oct.', 'Nuit · ven. 9 oct.', 'Matin · sam. 10 oct.'], $matrix->filter('thead th')->each(static fn ($th) => $th->text()));
        $rows = $matrix->filter('tbody tr');
        self::assertCount(2, $rows);
        self::assertStringStartsWith('Cave', $rows->eq(0)->filter('th')->text(), 'Pièces par ordre alphabétique.');
        self::assertStringContainsString('Chauffer à 19,0 °C', $rows->eq(0)->filter('td')->eq(0)->text());
        self::assertStringContainsString('dedans ≈ 14 °C', $rows->eq(0)->filter('td')->eq(0)->text());
        self::assertStringStartsWith('Salon', $rows->eq(1)->filter('th')->text());
        self::assertStringContainsString('Couper', $rows->eq(1)->filter('td')->eq(0)->text());
        self::assertStringContainsString('dedans ≈ 21 °C', $rows->eq(1)->filter('td')->eq(0)->text());
    }

    public function testARoomTargetReplacesTheHouseholdOneForThatRoomOnly(): void
    {
        // Mêmes données que ci-dessus : salon estimé à 20,7 °C l'après-midi, cave à 10,1 °C le matin.
        // Cible du foyer : 19 °C. Le salon est exigeant (21 °C l'après-midi), la cave tolère 10 °C le matin.
        $user = $this->createUser('a@example.com', located: true);
        $places = $this->addPlaceReadings($user, ['Salon' => 15.0, 'Cave' => 8.0]);
        $places['Salon']->setTarget(\App\Enum\DaySlot::Afternoon, 21.0);
        $places['Cave']->setTarget(\App\Enum\DaySlot::Morning, 10.0);
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations');

        $rows = $crawler->filter('#par-piece + div tbody tr');
        $cave = $rows->eq(0)->filter('td');
        $salon = $rows->eq(1)->filter('td');
        self::assertStringContainsString('Chauffer à 21,0 °C', $salon->eq(0)->text(), 'Salon, après-midi : 20,7 < 21.');
        self::assertStringContainsString('Couper', $cave->eq(3)->text(), 'Cave, matin de samedi : 10,1 ≥ 10.');
        // La vue du foyer ignore les cibles de pièce.
        self::assertStringContainsString('à 19,0 °C', $crawler->filter('#a-venir + ul li')->eq(0)->text());

        $view = $this->client->request('GET', '/recommandations', ['piece' => (string) $places['Salon']->getId()]);
        self::assertStringContainsString('visé 21,0 °C', $view->filter('tbody tr')->first()->filter('td')->eq(1)->text(), 'Après-midi du vendredi : cible de la pièce.');
        self::assertStringContainsString('visé 19,0 °C', $view->filter('tbody tr')->first()->filter('td')->eq(0)->text(), 'Matin : la pièce suit le foyer.');
    }

    public function testRoomViewShowsItsFifteenDays(): void
    {
        $user = $this->createUser('a@example.com', located: true);
        $places = $this->addPlaceReadings($user, ['Salon' => 15.0, 'Cave' => 8.0]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations', ['piece' => (string) $places['Cave']->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Recommandations · Cave');
        self::assertSelectorTextContains('#quinze-jours', 'Les 15 prochains jours — Cave');
        self::assertSelectorTextContains('#a-venir', 'À venir — Cave');
        self::assertSelectorNotExists('#par-piece', 'La vue d’une pièce ne répète pas la vue d’ensemble.');
        $current = $crawler->filter('nav[aria-label="Vue des recommandations"] a[aria-current=page]');
        self::assertCount(1, $current);
        self::assertSame('Cave', $current->text());
        // Matin du vendredi (cible 19), prévu à 8,5 : écart de la cave 3 − 0,4 × 3,5 = 1,6, soit 10,1 dedans → chauffer.
        $first = $crawler->filter('tbody tr')->first()->filter('td')->eq(0)->text();
        self::assertStringContainsString('Chauffer à 19,0 °C', $first);
        self::assertStringContainsString('dedans ≈ 10 °C', $first);
        self::assertCount(15, $crawler->filter('tbody tr'));
        self::assertSelectorNotExists('[role=status]:contains("Aucun relevé pour")');
    }

    public function testTabsListTheHouseholdAndEachRoomOnlyWhenThereAreSeveral(): void
    {
        $user = $this->createUser('a@example.com', located: true);
        $this->addPlaceReadings($user, ['Salon' => 15.0, 'Cave' => 8.0]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations');

        $tabs = $crawler->filter('nav[aria-label="Vue des recommandations"] a');
        self::assertSame(['Tout le foyer', 'Cave', 'Salon'], $tabs->each(static fn ($a) => $a->text()));
        self::assertSame('page', $crawler->filter('nav[aria-label="Vue des recommandations"] a')->first()->attr('aria-current'));
    }

    public function testASingleRoomAddsNeitherTabsNorMatrix(): void
    {
        $user = $this->createUser('a@example.com', located: true);
        $this->addPlaceReadings($user, ['Salon' => 15.0]);
        $this->client->loginUser($user);

        $this->client->request('GET', '/recommandations');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('nav[aria-label="Vue des recommandations"]');
        self::assertSelectorNotExists('#par-piece');
    }

    public function testRoomWithoutReadingsUsesTheHouseholdEstimateAndSaysSo(): void
    {
        $user = $this->createUser('a@example.com', located: true);
        $places = $this->addPlaceReadings($user, ['Salon' => 15.0, 'Cave' => 8.0]);
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $garage = new Place($household, 'Garage');
        $this->em->persist($garage);
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/recommandations');
        $row = $crawler->filter('#par-piece + div tbody tr')->reduce(static fn ($tr) => str_starts_with($tr->filter('th')->text(), 'Garage'));
        self::assertCount(1, $row);
        self::assertStringContainsString('estimation du foyer', $row->text());

        $view = $this->client->request('GET', '/recommandations', ['piece' => (string) $garage->getId()]);
        self::assertStringContainsString('Aucun relevé pour « Garage »', implode(' ', $view->filter('[role=status]')->each(static fn ($n) => $n->text())));
        self::assertSame(
            $crawler->filter('#quinze-jours + div tbody tr')->first()->filter('td')->eq(1)->text(),
            $view->filter('#quinze-jours + div tbody tr')->first()->filter('td')->eq(1)->text(),
            'Même estimation que le foyer.',
        );
        self::assertNotNull($places['Salon']->getId());
    }

    public function testUnknownOrForeignRoomIsNotFound(): void
    {
        $other = $this->createUser('other@example.com', located: true);
        $foreign = $this->addPlaceReadings($other, ['Salon' => 15.0, 'Cave' => 8.0]);
        $user = $this->createUser('a@example.com', located: true);
        $this->addPlaceReadings($user, ['Chambre' => 15.0, 'Bureau' => 12.0]);
        $this->client->loginUser($user);

        $this->client->request('GET', '/recommandations', ['piece' => (string) $foreign['Salon']->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/recommandations', ['piece' => '999999']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/recommandations', ['piece' => 'abc']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/recommandations', ['piece' => '']);
        self::assertResponseIsSuccessful();
    }

    public function testOutageIsReportedWithoutBreakingThePage(): void
    {
        $user = $this->createUser('a@example.com', located: true, latitude: 85.0);
        $this->addReadingsEverySlot($user, ['2026-10-08']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/recommandations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=alert]', 'momentanément indisponibles');
    }

    public function testEachHouseholdUsesItsOwnReadings(): void
    {
        $other = $this->createUser('other@example.com', located: true);
        $this->addReadingsEverySlot($other, ['2026-10-08']);
        $this->client->loginUser($this->createUser('a@example.com', located: true));

        $this->client->request('GET', '/recommandations');

        self::assertSelectorTextContains('body', 'Il manque deux choses');
        self::assertSelectorNotExists('table');
    }

    /**
     * Un jour de relevés (à 5 °C, quatre par jour et par pièce) avec la température intérieure donnée de chaque pièce.
     *
     * @param array<string, float> $indoorByPlace
     *
     * @return array<string, Place>
     */
    private function addPlaceReadings(User $user, array $indoorByPlace): array
    {
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $places = [];
        foreach ($indoorByPlace as $name => $indoor) {
            $place = new Place($household, $name);
            $this->em->persist($place);
            foreach (['03:00', '08:00', '14:00', '20:00'] as $time) {
                $this->em->persist(new Reading($place, new \DateTimeImmutable('2026-10-08 '.$time), 5.0, $indoor));
            }
            $places[$name] = $place;
        }
        $this->em->flush();

        return $places;
    }

    /**
     * Quatre relevés par jour (un par créneau), tous avec un écart de +10 °C.
     *
     * @param list<string> $days
     */
    private function addReadingsEverySlot(User $user, array $days): void
    {
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $place = new Place($household, 'Salon');
        $this->em->persist($place);
        foreach ($days as $day) {
            foreach (['03:00', '08:00', '14:00', '20:00'] as $time) {
                $this->em->persist(new Reading($place, new \DateTimeImmutable($day.' '.$time), 5.0, 15.0));
            }
        }
        $this->em->flush();
    }

    private function createUser(string $email, bool $located = false, float $latitude = 45.75): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setVerified(true);
        $household = (new Household($user))->completeSetup(new \DateTimeImmutable('2026-01-01'));
        if ($located) {
            $household->locate('Lyon (Rhône, France)', $latitude, 4.85, 'Europe/Paris');
        }
        $this->em->persist($user);
        $this->em->persist($household);
        $this->em->flush();

        return $user;
    }
}
