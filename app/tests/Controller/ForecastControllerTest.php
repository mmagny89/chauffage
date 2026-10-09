<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Household;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class ForecastControllerTest extends WebTestCase
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
        $this->client->request('GET', '/previsions');

        self::assertResponseRedirects('/login');
    }

    public function testHouseholdWithoutCityIsInvitedToChooseOne(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/previsions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'indiquez d’abord votre ville');
        self::assertSelectorExists('a[href="/reglages"]');
        self::assertSelectorNotExists('table');
    }

    public function testShowsFifteenDaysByFourSlots(): void
    {
        $this->client->loginUser($this->createUser('a@example.com', 'Lyon (Rhône, France)'));

        $crawler = $this->client->request('GET', '/previsions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('p', 'Lyon (Rhône, France)');
        $rows = $crawler->filter('tbody tr');
        self::assertCount(15, $rows);
        self::assertStringContainsString('ven. 9 oct.', $rows->first()->filter('th')->text());
        self::assertStringContainsString('ven. 23 oct.', $rows->last()->filter('th')->text());
        self::assertCount(4, $rows->first()->filter('td'));

        $first = $rows->first()->filter('td');
        self::assertStringContainsString('8,5 °C', $first->eq(0)->text(), 'Matin : 6 h à 11 h.');
        self::assertStringContainsString('14,5 °C', $first->eq(1)->text());
        self::assertStringContainsString('19,5 °C', $first->eq(2)->text());
        self::assertStringContainsString('7,5 °C', $first->eq(3)->text(), 'Nuit, en dernier : 22 h, 23 h et 0 h à 5 h du lendemain.');
        self::assertSelectorNotExists('td span[title]', 'Quinze nuits complètes : aucun créneau tronqué.');
    }

    public function testCreditsOpenMeteo(): void
    {
        $this->client->loginUser($this->createUser('a@example.com', 'Lyon'));

        $this->client->request('GET', '/previsions');

        self::assertSelectorExists('a[href="https://open-meteo.com/"]');
        self::assertSelectorExists('a[href="https://creativecommons.org/licenses/by/4.0/"]');
    }

    public function testOutageIsReportedWithoutBreakingThePage(): void
    {
        $user = $this->createUser('a@example.com', 'Longyearbyen', 85.0);
        $this->client->loginUser($user);

        $this->client->request('GET', '/previsions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=alert]', 'momentanément indisponibles');
        self::assertSelectorNotExists('table');
    }

    private function createUser(string $email, ?string $city = null, float $latitude = 45.75): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setVerified(true);
        $household = (new Household($user))->completeSetup(new \DateTimeImmutable('2026-01-01'));
        if (null !== $city) {
            $household->locate($city, $latitude, 4.85, 'Europe/Paris');
        }
        $this->em->persist($user);
        $this->em->persist($household);
        $this->em->flush();

        return $user;
    }
}
