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

final class DeltaControllerTest extends WebTestCase
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
        $this->client->request('GET', '/ecarts');

        self::assertResponseRedirects('/login');
    }

    public function testEmptyHouseholdIsInvitedToEnterReadings(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/ecarts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucun relevé pour le moment');
        self::assertSelectorExists('a[href="/releves"]');
        self::assertSelectorNotExists('table');
    }

    public function testShowsAveragesPerPlaceAndSlot(): void
    {
        $user = $this->createUser('a@example.com');
        $this->addReadings($user, 'Salon', [['2026-10-07 07:00', 5.0, 18.0], ['2026-10-08 08:00', 3.0, 18.0]]);
        $this->addReadings($user, 'Cave', [['2026-10-08 07:30', 5.0, 12.0]]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/ecarts');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('table')->first()->filter('tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('Cave', $rows->eq(0)->text());
        self::assertStringContainsString('+7,0 °C', $rows->eq(0)->text());
        self::assertStringContainsString('Salon', $rows->eq(1)->text());
        self::assertStringContainsString('+14,0 °C', $rows->eq(1)->text());
        self::assertStringContainsString('2 relevés', $rows->eq(1)->text());
        self::assertStringContainsString('—', $rows->eq(1)->text(), 'Créneaux sans relevé.');
        self::assertStringContainsString('Tous les lieux', $crawler->filter('table')->first()->filter('tfoot')->text());
    }

    public function testExplainsTheTypicalSlopeWhenThereAreTooFewReadings(): void
    {
        $user = $this->createUser('a@example.com');
        $this->addReadings($user, 'Salon', [['2026-10-07 07:00', 5.0, 18.0], ['2026-10-08 08:00', 3.0, 18.0]]);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/ecarts');

        $model = $crawler->filter('#modele')->ancestors()->first()->text();
        self::assertStringContainsString('Pente typique', $model);
        self::assertStringContainsString('Trop peu de relevés', $model);
        self::assertStringContainsString('ils pèsent pour', $model);
        self::assertStringContainsString('Aucun relevé : le modèle de tous les créneaux est utilisé', $model);
        self::assertStringNotContainsString('Pente mesurée', $model);
    }

    public function testShowsTheRegressionFormulaWhenReadingsAreVariedEnough(): void
    {
        // Matin, cinq jours : intérieur = 8 + 0,6 × extérieur, donc écart = 8 − 0,4 × extérieur.
        $user = $this->createUser('a@example.com');
        $this->addReadings($user, 'Salon', array_map(
            static fn (int $d, float $t): array => [\sprintf('2026-10-%02d 08:00', $d), $t, round(8.0 + 0.6 * $t, 1)],
            [1, 2, 3, 4, 5],
            [2.0, 5.0, 8.0, 11.0, 14.0],
        ));
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/ecarts');

        $model = $crawler->filter('#modele')->ancestors()->first()->text();
        self::assertStringContainsString('Pente mesurée', $model);
        self::assertStringContainsString('Les relevés pèsent pour 78 % dans la pente', $model);
        self::assertStringContainsString('écart = 8,0 − 0,40 × T° extérieure', $model);
        self::assertStringContainsString('intérieur ≈ 8,0 + 0,60 × T° extérieure', $model);
        self::assertStringContainsString('2,0 à 14,0 °C', $model);
    }

    public function testResultsAreFlaggedProvisionalBeforeFiveDays(): void
    {
        $user = $this->createUser('a@example.com');
        $this->addReadings($user, 'Salon', [['2026-10-07 07:00', 5.0, 18.0], ['2026-10-08 07:00', 5.0, 18.0]]);
        $this->client->loginUser($user);

        $this->client->request('GET', '/ecarts');

        self::assertSelectorTextContains('[role=status]', 'Résultats provisoires : 2 jours de relevés sur 5');
    }

    public function testNoProvisionalNoticeOnceFiveDaysAreEntered(): void
    {
        $user = $this->createUser('a@example.com');
        $this->addReadings($user, 'Salon', array_map(static fn (int $d): array => [\sprintf('2026-10-%02d 07:00', $d), 5.0, 18.0], [1, 2, 3, 4, 5]));
        $this->client->loginUser($user);

        $this->client->request('GET', '/ecarts');

        self::assertSelectorNotExists('[role=status]');
    }

    public function testEachHouseholdOnlySeesItsOwnPlaces(): void
    {
        $this->addReadings($this->createUser('a@example.com'), 'Chambre secrète', [['2026-10-08 07:00', 5.0, 18.0]]);
        $this->client->loginUser($this->createUser('b@example.com'));

        $this->client->request('GET', '/ecarts');

        self::assertSelectorTextNotContains('body', 'Chambre secrète');
        self::assertSelectorTextContains('body', 'Aucun relevé pour le moment');
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setVerified(true);
        $this->em->persist($user);
        $this->em->persist((new Household($user))->completeSetup(new \DateTimeImmutable('2026-01-01')));
        $this->em->flush();

        return $user;
    }

    /**
     * @param list<array{string, float, float}> $readings
     */
    private function addReadings(User $user, string $placeName, array $readings): void
    {
        $household = $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
        $place = new Place($household, $placeName);
        $this->em->persist($place);
        foreach ($readings as [$measuredAt, $outdoor, $indoor]) {
            $this->em->persist(new Reading($place, new \DateTimeImmutable($measuredAt), $outdoor, $indoor));
        }
        $this->em->flush();
    }
}
