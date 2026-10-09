<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Garde-fou contre les requêtes N+1 : le nombre de requêtes SQL d'une page ne dépend pas
 * du nombre de relevés ni de lieux. Un budget par page, mesuré avec un foyer fourni
 * (10 lieux, 30 jours, 4 relevés par jour et par lieu : 1 200 relevés) : 1 à 6 requêtes
 * par page, budget = mesure + une de marge.
 */
final class QueryBudgetTest extends WebTestCase
{
    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-10-09 14:00:00', 'Europe/Paris'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, int}> chemin, budget de requêtes
     */
    public static function budgets(): iterable
    {
        yield 'accueil' => ['/', 3];
        yield 'relevés' => ['/releves', 8];
        yield 'écarts' => ['/ecarts', 7];
        yield 'réglages' => ['/reglages', 6];
        yield 'prévisions' => ['/previsions', 5];
        yield 'recommandations' => ['/recommandations', 7];
    }

    #[DataProvider('budgets')]
    public function testQueryCountStaysWithinBudget(string $path, int $budget): void
    {
        $client = static::createClient();
        $user = $this->bigHousehold();
        // Les insertions du jeu de test seraient comptées : un conteneur neuf ne mesure que la page
        // (DAMA garde les données entre les deux).
        $client->getKernel()->shutdown();
        $client->getKernel()->boot();
        $client->loginUser($user);
        $client->enableProfiler();

        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        self::assertLessThanOrEqual($budget, $collector->getQueryCount(), \sprintf('%s : %d requêtes (budget %d).', $path, $collector->getQueryCount(), $budget));
    }

    private function bigHousehold(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('volume@example.com')->setPassword('x')->setVerified(true);
        $household = new Household($user);
        $household->locate('Lyon (Rhône, France)', 45.74906, 4.84789, 'Europe/Paris');
        $em->persist($user);
        $em->persist($household);

        for ($p = 1; $p <= 10; ++$p) {
            $place = new Place($household, 'Lieu '.$p);
            $em->persist($place);
            for ($day = 1; $day <= 30; ++$day) {
                foreach (['03:00', '08:00', '14:00', '20:00'] as $time) {
                    $outdoor = (float) (($day * 7 + $p) % 15);
                    $em->persist(new Reading($place, new \DateTimeImmutable(\sprintf('2026-09-%02d %s', $day, $time)), $outdoor, round(8.0 + 0.6 * $outdoor, 1)));
                }
            }
        }
        $em->flush();
        $em->clear();

        return $user;
    }
}
