<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Household;
use App\Entity\Place;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Un foyer qui vient d'être créé règle d'abord (ville, lieux, températures visées),
 * puis relève : tant que la mise en route n'est pas terminée, tout renvoie vers les réglages.
 */
final class SetupFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lockedPages(): iterable
    {
        yield 'accueil' => ['/'];
        yield 'relevés' => ['/releves'];
        yield 'écarts' => ['/ecarts'];
        yield 'prévisions' => ['/previsions'];
        yield 'recommandations' => ['/recommandations'];
    }

    #[DataProvider('lockedPages')]
    public function testEveryPageSendsANewHouseholdToTheSettings(string $path): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('GET', $path);

        self::assertResponseRedirects('/reglages');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', 'Avant de saisir des relevés, terminez la mise en route');
    }

    public function testReadingsCannotBeRecordedBeforeTheSetupIsDone(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $this->client->request('POST', '/releves', ['reading_session' => ['date' => '2026-10-08']]);

        self::assertResponseRedirects('/reglages');
    }

    public function testSettingsAreReachableAndPresentTheThreeSteps(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $crawler = $this->client->request('GET', '/reglages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mise en route');
        self::assertSelectorTextContains('title', 'Mise en route');
        $steps = $crawler->filter('#mise-en-route + p + ol li')->each(static fn ($li) => $li->text());
        self::assertCount(3, $steps);
        self::assertStringContainsString('1. Votre ville — à choisir', $steps[0]);
        self::assertStringContainsString('2. Vos lieux — au moins un à déclarer', $steps[1]);
        self::assertStringContainsString('3. Vos températures visées', $steps[2]);
        self::assertSelectorTextContains('#ville', '1. Votre ville');
        self::assertSelectorTextContains('#titre-lieux', '2. Vos lieux');
        self::assertSelectorTextContains('#cibles', '3. Températures visées du foyer');
    }

    public function testFinishButtonIsDisabledUntilCityAndPlaceAreSet(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/reglages');
        $button = $crawler->filter('form[action="/reglages/terminer"] button');
        self::assertNotNull($button->attr('disabled'));
        self::assertSelectorTextContains('#reste-a-faire', 'choisissez votre ville, déclarez au moins un lieu');

        $this->locate($user);
        $crawler = $this->client->request('GET', '/reglages');
        self::assertNotNull($crawler->filter('form[action="/reglages/terminer"] button')->attr('disabled'));
        self::assertSelectorTextContains('#reste-a-faire', 'déclarez au moins un lieu');
        self::assertStringNotContainsString('choisissez votre ville', $crawler->filter('#reste-a-faire')->text());

        $this->addPlace($user);
        $crawler = $this->client->request('GET', '/reglages');
        self::assertNull($crawler->filter('form[action="/reglages/terminer"] button')->attr('disabled'));
        self::assertSelectorNotExists('#reste-a-faire');
    }

    public function testFinishingIsRefusedServerSideWhenStepsAreMissing(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/reglages');
        $token = (string) $crawler->filter('form[action="/reglages/terminer"] input[name=_token]')->attr('value');

        $this->client->request('POST', '/reglages/terminer', ['_token' => $token]);

        self::assertResponseRedirects('/reglages');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Pour terminer la mise en route : choisissez votre ville, déclarez au moins un lieu.');
        $this->em->clear();
        self::assertFalse($this->household($user)->isSetUp());
    }

    public function testFinishingUnlocksTheApplication(): void
    {
        $user = $this->createUser('a@example.com');
        $this->locate($user);
        $this->addPlace($user);
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/reglages');

        $this->client->submit($crawler->filter('form[action="/reglages/terminer"]')->form());

        self::assertResponseRedirects('/releves');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', 'Mise en route terminée');
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertTrue($this->household($user)->isSetUp());

        foreach (['/', '/ecarts', '/previsions', '/recommandations'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testSettingsReturnToNormalOnceTheSetupIsDone(): void
    {
        $user = $this->createUser('a@example.com', setUp: true);
        $this->client->loginUser($user);

        $this->client->request('GET', '/reglages');

        self::assertSelectorTextContains('h1', 'Réglages');
        self::assertSelectorNotExists('#mise-en-route');
        self::assertSelectorNotExists('form[action="/reglages/terminer"]');
    }

    public function testFinishWithAnInvalidCsrfTokenIsRefused(): void
    {
        $user = $this->createUser('a@example.com');
        $this->locate($user);
        $this->addPlace($user);
        $this->client->loginUser($user);

        $this->client->request('POST', '/reglages/terminer', ['_token' => 'invalide']);

        self::assertResponseRedirects('/reglages');
        $this->em->clear();
        self::assertFalse($this->household($user)->isSetUp());
    }

    public function testFinishingTwiceJustGoesToTheReadings(): void
    {
        $user = $this->createUser('a@example.com');
        $this->locate($user);
        $this->addPlace($user);
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/reglages');
        $token = (string) $crawler->filter('form[action="/reglages/terminer"] input[name=_token]')->attr('value');

        $this->client->request('POST', '/reglages/terminer', ['_token' => $token]);
        $this->em->clear();
        $firstDate = $this->household($user)->isSetUp();
        $this->client->request('POST', '/reglages/terminer', ['_token' => $token]);

        self::assertTrue($firstDate);
        self::assertResponseRedirects('/releves');
    }

    public function testTheSettingsActionsStayAvailableDuringTheSetup(): void
    {
        $user = $this->createUser('a@example.com');
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/reglages');
        $token = (string) $crawler->filter('form[action="/reglages/lieux"] input[name=_token]')->attr('value');

        $this->client->request('POST', '/reglages/lieux', ['choice' => 'Cuisine', '_token' => $token]);

        self::assertResponseRedirects('/reglages#lieux');
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(Place::class)->count([]));

        $crawler = $this->client->request('GET', '/reglages', ['q' => 'Lyon']);
        self::assertSelectorTextContains('#resultats', '2 résultats');
        $this->client->submit($crawler->filter('ul[aria-labelledby=resultats] li')->first()->selectButton('Choisir')->form());
        self::assertResponseRedirects('/reglages');
    }

    public function testLoggingOutIsPossibleDuringTheSetup(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));
        $crawler = $this->client->request('GET', '/reglages');

        $this->client->click($crawler->selectLink('Se déconnecter')->link());

        self::assertResponseRedirects('/login');
    }

    public function testTheMenuOffersNoLinkToLockedPages(): void
    {
        $this->client->loginUser($this->createUser('a@example.com'));

        $crawler = $this->client->request('GET', '/reglages');

        self::assertCount(0, $crawler->filter('header a[href="/releves"], header a[href="/recommandations"], header a[href="/ecarts"], header a[href="/previsions"]'));
        self::assertCount(1, $crawler->filter('header a[href="/reglages"]'));
    }

    public function testAnonymousVisitorsAreNotConcerned(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
    }

    private function createUser(string $email, bool $setUp = false): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setVerified(true);
        $household = new Household($user);
        if ($setUp) {
            $household->completeSetup(new \DateTimeImmutable('2026-01-01'));
        }
        $this->em->persist($user);
        $this->em->persist($household);
        $this->em->flush();

        return $user;
    }

    private function household(User $user): Household
    {
        return $this->em->getRepository(Household::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Foyer introuvable.');
    }

    private function locate(User $user): void
    {
        $this->household($user)->locate('Lyon (Rhône, France)', 45.74906, 4.84789, 'Europe/Paris');
        $this->em->flush();
    }

    private function addPlace(User $user): void
    {
        $this->em->persist(new Place($this->household($user), 'Salon'));
        $this->em->flush();
    }
}
