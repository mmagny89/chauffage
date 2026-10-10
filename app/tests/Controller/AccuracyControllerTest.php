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

final class AccuracyControllerTest extends WebTestCase
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
        $this->client->request('GET', '/fiabilite');

        self::assertResponseRedirects('/login');
    }

    public function testWithoutEnoughReadingsThePageInvitesToEnterSome(): void
    {
        $user = $this->createUser();
        $this->addReadings($user, [['2026-10-08 08:00', 5.0, 18.0]]);
        $this->client->loginUser($user);

        $this->client->request('GET', '/fiabilite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'au moins deux séances');
        self::assertSelectorNotExists('table');
    }

    public function testShowsTheErrorPerSlotAndPlaceAndFlagsProvisionalResults(): void
    {
        $user = $this->createUser();
        $this->addReadings($user, [['2026-10-07 08:00', 5.0, 18.0], ['2026-10-08 08:00', 5.0, 20.0]]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/fiabilite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=status]', 'Résultats provisoires : 2 séances de relevés sur 8');
        self::assertSelectorTextContains('[aria-labelledby=bilan]', 'se trompe en moyenne de 2,0 °C');
        $morning = $crawler->filter('#par-creneau + div tbody tr')->first();
        self::assertStringContainsString('Matin', $morning->text());
        self::assertStringContainsString('2,0 °C', $morning->text());
        self::assertStringContainsString('0 %', $morning->text());
        self::assertStringContainsString('Salon', $crawler->filter('#par-lieu + div tbody')->text());
    }

    public function testAMenuItemStaysOnTheDeltasEntry(): void
    {
        $user = $this->createUser();
        $this->addReadings($user, [['2026-10-07 08:00', 5.0, 18.0], ['2026-10-08 08:00', 5.0, 18.0]]);
        $this->client->loginUser($user);

        $this->client->request('GET', '/fiabilite');

        self::assertSelectorExists('header a[aria-current=page][href="/ecarts"]');
    }

    /**
     * @return list<array{string, float, float}>
     */
    private function steepReadings(): array
    {
        // Écart réel qui chute de 0,8 °C par degré sur des relevés peu variés : une pente plus raide est proposée.
        $readings = [];
        foreach ([2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0] as $i => $outdoor) {
            $readings[] = [\sprintf('2026-10-%02d 08:00', $i + 1), $outdoor, round($outdoor + 20 - 0.8 * $outdoor, 1)];
        }

        return $readings;
    }

    private function household(User $user): Household
    {
        $this->em->clear();

        return $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
    }

    public function testTheSlopeSectionExplainsWhyNothingIsProposedWithFewSessions(): void
    {
        $user = $this->createUser();
        $this->addReadings($user, [['2026-10-07 08:00', 5.0, 18.0], ['2026-10-08 08:00', 5.0, 20.0]]);
        $this->client->loginUser($user);

        $this->client->request('GET', '/fiabilite');

        self::assertSelectorTextContains('[aria-labelledby=pente]', 'au moins 8 séances');
        self::assertSelectorNotExists('[aria-labelledby=pente] button:contains("Appliquer")');
    }

    public function testASteeperSlopeIsProposedAndCanBeApplied(): void
    {
        $user = $this->createUser();
        $this->addReadings($user, $this->steepReadings());
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/fiabilite');

        self::assertSelectorTextContains('[aria-labelledby=pente]', 'aurait mieux prédit vos relevés');
        $token = (string) $crawler->filter('[aria-labelledby=pente] form input[name=action][value=apply]')->closest('form')?->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', '/fiabilite/pente', ['_token' => $token, 'action' => 'apply']);

        self::assertResponseRedirects('/fiabilite');
        $slope = $this->household($user)->getTypicalSlope();
        self::assertNotNull($slope);
        self::assertLessThanOrEqual(-0.7, $slope);
        self::assertFalse($this->household($user)->isAutoTuneSlope());

        $this->client->followRedirect();
        self::assertSelectorTextContains('[aria-labelledby=pente]', 'Pente réglée pour votre foyer');
    }

    public function testAutomaticTuningUsesTheBestSlopeAndResetGoesBack(): void
    {
        $user = $this->createUser();
        $this->addReadings($user, $this->steepReadings());
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/fiabilite');
        $token = (string) $crawler->filter('[aria-labelledby=pente] input[name=_token]')->attr('value');
        $this->client->request('POST', '/fiabilite/pente', ['_token' => $token, 'action' => 'auto_on']);
        self::assertTrue($this->household($user)->isAutoTuneSlope());

        $this->client->request('GET', '/ecarts');
        self::assertSelectorTextContains('main', 'réglée pour votre foyer');

        $this->client->request('POST', '/fiabilite/pente', ['_token' => $token, 'action' => 'reset']);
        $household = $this->household($user);
        self::assertFalse($household->isAutoTuneSlope());
        self::assertNull($household->getTypicalSlope());
    }

    public function testSlopeChangeNeedsAValidTokenAndKnownAction(): void
    {
        $user = $this->createUser();
        $this->addReadings($user, $this->steepReadings());
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/fiabilite');
        $token = (string) $crawler->filter('[aria-labelledby=pente] input[name=_token]')->attr('value');

        $this->client->request('POST', '/fiabilite/pente', ['_token' => 'invalide', 'action' => 'auto_on']);
        self::assertFalse($this->household($user)->isAutoTuneSlope());

        $this->client->request('POST', '/fiabilite/pente', ['_token' => $token, 'action' => 'n-importe-quoi']);
        self::assertFalse($this->household($user)->isAutoTuneSlope());
        self::assertNull($this->household($user)->getTypicalSlope());
    }

    public function testSlopeEndpointRefusesAnonymousAndGet(): void
    {
        $this->client->request('POST', '/fiabilite/pente', ['action' => 'reset']);
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/fiabilite/pente');
        self::assertResponseStatusCodeSame(405);
    }

    private function createUser(): User
    {
        $user = (new User())->setEmail('a@example.com')->setPassword('x')->setVerified(true);
        $household = (new Household($user))->completeSetup(new \DateTimeImmutable('2026-01-01'));
        $household->locate('Lyon (Rhône, France)', 45.75, 4.85, 'Europe/Paris');
        $this->em->persist($user);
        $this->em->persist($household);
        $this->em->flush();

        return $user;
    }

    /**
     * @param list<array{string, float, float}> $readings
     */
    private function addReadings(User $user, array $readings): void
    {
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $place = new Place($household, 'Salon');
        $this->em->persist($place);
        foreach ($readings as [$at, $outdoor, $indoor]) {
            $this->em->persist(new Reading($place, new \DateTimeImmutable($at), $outdoor, $indoor));
        }
        $this->em->flush();
    }
}
