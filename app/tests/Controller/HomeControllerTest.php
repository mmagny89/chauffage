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

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
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

    public function testQuickLinksCoverEveryArea(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $crawler = $this->client->request('GET', '/');

        $links = $crawler->filter('#acces + ul a')->each(static fn ($a) => (string) $a->attr('href'));
        self::assertSame(['/releves', '/recommandations', '/ecarts', '/previsions'], $links);
    }

    private function createUser(string $email, float $latitude = 45.75): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setVerified(true);
        $household = (new Household($user))->completeSetup(new \DateTimeImmutable('2026-01-01'));
        $household->locate('Lyon (Rhône, France)', $latitude, 4.85, 'Europe/Paris');
        $this->em->persist($user);
        $this->em->persist($household);
        $this->em->flush();

        return $user;
    }

    /**
     * @param list<string> $days
     */
    private function addReadings(User $user, array $days): void
    {
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $place = new Place($household, 'Salon');
        $this->em->persist($place);
        foreach ($days as $day) {
            $this->em->persist(new Reading($place, new \DateTimeImmutable($day.' 08:00'), 5.0, 15.0));
        }
        $this->em->flush();
    }
}
