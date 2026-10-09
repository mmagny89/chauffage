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
        // Écart +10 partout ; dehors (double de test) : matin 8,5 / après-midi 14,5 / soirée 19,5 / nuit 7,5
        // → dedans estimé 18,5 / 24,5 / 29,5 / 17,5 ; cibles 19 / 19 / 20 / 17.
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
        self::assertStringContainsString('dedans ≈ 18,5 °C', $cells->eq(0)->text());
        self::assertStringContainsString('Couper', $cells->eq(1)->text());
        self::assertStringContainsString('dehors 14,5 °C', $cells->eq(1)->text());
        self::assertStringContainsString('Couper', $cells->eq(2)->text());
        self::assertStringContainsString('Couper', $cells->eq(3)->text());
        self::assertStringContainsString('dedans ≈ 17,5 °C', $cells->eq(3)->text());
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
        $household->targetFor(\App\Enum\Weekday::Friday, \App\Enum\DaySlot::Morning)->setTemperature(18.0); // vendredi 9 : 18,5 ≥ 18, on coupe
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
        $friday = $rows->eq(0)->filter('td')->eq(0)->text();   // matin, estimé 18,5
        $saturday = $rows->eq(1)->filter('td')->eq(0)->text();
        self::assertStringContainsString('ven. 9 oct.', $rows->eq(0)->text());
        self::assertStringContainsString('sam. 10 oct.', $rows->eq(1)->text());
        self::assertStringContainsString('Chauffer à 19,0 °C', $friday);
        self::assertStringContainsString('visé 19,0 °C', $friday);
        self::assertStringContainsString('Couper', $saturday, 'Samedi la cible est 17 : 18,5 suffit.');
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
        // Matin : 8,5 + (8 − 0,4 × 8,5 = 4,6) = 13,1
        self::assertStringContainsString('dedans ≈ 13,1 °C', $cells->eq(0)->text());
        self::assertStringNotContainsString('hors plage mesurée', $cells->eq(0)->text());
        // Après-midi : 14,5 reste dans la marge (14 + 3) ; soirée : 19,5 la dépasse.
        self::assertStringNotContainsString('hors plage mesurée', $cells->eq(1)->text());
        self::assertStringContainsString('hors plage mesurée', $cells->eq(2)->text());
        self::assertStringContainsString('écart général', $cells->eq(2)->text());
        self::assertSelectorExists('a[href="/ecarts#modele"]');
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
        $household = new Household($user);
        if ($located) {
            $household->locate('Lyon (Rhône, France)', $latitude, 4.85, 'Europe/Paris');
        }
        $this->em->persist($user);
        $this->em->persist($household);
        $this->em->flush();

        return $user;
    }
}
