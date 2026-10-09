<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Household;
use App\Entity\User;
use App\Enum\DaySlot;
use App\Enum\Weekday;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class SettingsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('test.cache.rate_limiter')->clear();
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/reglages');

        self::assertResponseRedirects('/login');
    }

    public function testShowsDefaultTargetsAndNoCity(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/reglages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucune ville renseignée');
        foreach (['monday', 'wednesday', 'sunday'] as $day) {
            self::assertInputValueSame(\sprintf('week_targets[%s][night]', $day), '17.0');
            self::assertInputValueSame(\sprintf('week_targets[%s][morning]', $day), '19.0');
            self::assertInputValueSame(\sprintf('week_targets[%s][afternoon]', $day), '19.0');
            self::assertInputValueSame(\sprintf('week_targets[%s][evening]', $day), '20.0');
        }
        self::assertCount(28, $this->client->getCrawler()->filter('input[name^="week_targets["][type=number]'));
        self::assertSame(['Jour', 'Matin', 'Après-midi', 'Soirée', 'Nuit'], $this->client->getCrawler()->filter('thead th')->each(static fn ($th) => $th->text()));
    }

    public function testTargetsAreSaved(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/reglages');
        $this->client->submitForm('Enregistrer', [
            'week_targets[monday][night]' => '16.5',
            'week_targets[monday][afternoon]' => '18',
            'week_targets[saturday][evening]' => '21.5',
            'week_targets[sunday][morning]' => '20',
        ]);

        self::assertResponseRedirects('/reglages');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', 'Températures visées enregistrées');

        $household = $this->household($user);
        self::assertSame(16.5, $household->targetFor(Weekday::Monday, DaySlot::Night)->getTemperature());
        self::assertSame(18.0, $household->targetFor(Weekday::Monday, DaySlot::Afternoon)->getTemperature());
        self::assertSame(21.5, $household->targetFor(Weekday::Saturday, DaySlot::Evening)->getTemperature());
        self::assertSame(20.0, $household->targetFor(Weekday::Sunday, DaySlot::Morning)->getTemperature());
        self::assertSame(17.0, $household->targetFor(Weekday::Tuesday, DaySlot::Night)->getTemperature(), 'Les autres jours ne bougent pas.');
        self::assertSame(19.0, $household->targetFor(Weekday::Saturday, DaySlot::Morning)->getTemperature());
    }

    public function testOutOfRangeTargetIsRefusedAndNothingChanges(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/reglages');
        $this->client->submitForm('Enregistrer', [
            'week_targets[thursday][night]' => '3',
            'week_targets[friday][evening]' => '45',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'doit être comprise entre 5 et 30');
        $this->em->clear();
        self::assertSame(17.0, $this->household($user)->targetFor(Weekday::Thursday, DaySlot::Night)->getTemperature());
    }

    public function testCitySearchListsResultsWithRegion(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $crawler = $this->client->request('GET', '/reglages', ['q' => 'Lyon']);

        self::assertSelectorTextContains('#resultats', '2 résultats');
        $items = $crawler->filter('ul[aria-labelledby=resultats] li');
        self::assertCount(2, $items);
        self::assertStringContainsString('Lyon (Rhône, France)', $items->eq(0)->text());
        self::assertStringContainsString('Lyon (Mississippi, États-Unis)', $items->eq(1)->text());
    }

    public function testCitySearchWithoutMatch(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/reglages', ['q' => 'Atlantide']);

        self::assertSelectorTextContains('[role=status]', 'Aucune ville ne correspond à « Atlantide »');
    }

    public function testCitySearchOutageIsReportedWithoutBreakingThePage(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', '/reglages', ['q' => 'Panne']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=alert]', 'momentanément indisponible');
        self::assertSelectorExists('form[name=week_targets]', 'Le reste de la page reste utilisable.');
    }

    public function testChoosingACityLocatesTheHousehold(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/reglages', ['q' => 'Lyon']);
        $this->client->submit($this->choiceForm($crawler, 0));

        self::assertResponseRedirects('/reglages');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', 'Ville enregistrée : Lyon (Rhône, France).');
        self::assertSelectorTextContains('body', 'Ville actuelle : Lyon (Rhône, France)');

        $household = $this->household($user);
        self::assertSame('Lyon (Rhône, France)', $household->getCity());
        self::assertSame(45.74906, $household->getLatitude());
        self::assertSame(4.84789, $household->getLongitude());
        self::assertSame('Europe/Paris', $household->getTimezone());
        self::assertNotNull($crawler);
    }

    public function testChoosingTheOtherCityKeepsItsTimezone(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/reglages', ['q' => 'Lyon']);
        $this->client->submit($this->choiceForm($crawler, 1));

        self::assertSame('America/Chicago', $this->household($user)->getTimezone());
        self::assertSame(-90.54204, $this->household($user)->getLongitude());
    }

    public function testCityChoiceWithInvalidCsrfTokenIsRefused(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/reglages/ville', $this->validChoice() + ['_token' => 'invalide']);

        self::assertResponseRedirects('/reglages');
        $this->em->clear();
        self::assertNull($this->household($user)->getCity());
    }

    /**
     *
     * @param array<string, string> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tamperedChoices')]
    public function testTamperedCityChoiceIsRefused(array $override): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/reglages', ['q' => 'Lyon']);
        $token = (string) $crawler->filter('input[name=_token]')->first()->attr('value');

        $this->client->request('POST', '/reglages/ville', array_merge($this->validChoice(), $override, ['_token' => $token]));

        self::assertResponseRedirects('/reglages');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status], [role=alert]', 'n’a pas pu être enregistrée');
        $this->em->clear();
        self::assertNull($this->household($user)->getCity());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function tamperedChoices(): iterable
    {
        yield 'latitude hors limites' => [['latitude' => '123']];
        yield 'longitude hors limites' => [['longitude' => '-200']];
        yield 'latitude non numérique' => [['latitude' => 'abc']];
        yield 'fuseau inconnu' => [['timezone' => 'Mars/Olympus']];
        yield 'libellé vide' => [['label' => '']];
        yield 'libellé trop long' => [['label' => str_repeat('x', 121)]];
    }

    public function testSearchIsRateLimited(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        for ($i = 0; $i < 31; ++$i) {
            $this->client->request('GET', '/reglages', ['q' => 'Atlantide']);
        }

        self::assertSelectorTextContains('[role=alert]', 'Trop de recherches');
    }

    public function testEachHouseholdHasItsOwnSettings(): void
    {
        $a = $this->createUser('a@example.com');
        $b = $this->createUser('b@example.com');
        $this->client->loginUser($a);
        $crawler = $this->client->request('GET', '/reglages', ['q' => 'Lyon']);
        $this->client->submit($this->choiceForm($crawler, 0));

        $this->em->clear();

        self::assertSame('Lyon (Rhône, France)', $this->household($a)->getCity());
        self::assertNull($this->household($b)->getCity());
    }

    /**
     * @return array<string, string>
     */
    private function validChoice(): array
    {
        return ['label' => 'Lyon (Rhône, France)', 'latitude' => '45.74906', 'longitude' => '4.84789', 'timezone' => 'Europe/Paris'];
    }

    private function choiceForm(Crawler $crawler, int $index): \Symfony\Component\DomCrawler\Form
    {
        return $crawler->filter('ul[aria-labelledby=resultats] li')->eq($index)->selectButton('Choisir')->form();
    }

    private function household(User $user): Household
    {
        return $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
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
