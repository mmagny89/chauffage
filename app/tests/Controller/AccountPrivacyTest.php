<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\HeatingStart;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use App\Tests\Support\ResetsRateLimiters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Droit d'accès / portabilité (export JSON) et droit à l'effacement (suppression du compte).
 */
final class AccountPrivacyTest extends WebTestCase
{
    use ResetsRateLimiters;

    private const PASSWORD = 'cheval agrafeuse nuage tricot';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-10-09 14:00:00', 'Europe/Paris'));
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::resetRateLimiters();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/compte/export');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/compte/supprimer');
        self::assertResponseRedirects('/login');
    }

    public function testExportContainsTheAccountDataAndNothingSecret(): void
    {
        $user = $this->createUser('a@example.com', 'Salon');
        $this->createUser('autre@example.com', 'Chambre d’un autre');
        $this->client->loginUser($user);

        $this->client->request('GET', '/compte/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertStringContainsString('attachment; filename=chauffage-donnees-2026-10-09.json', (string) $this->client->getResponse()->headers->get('content-disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('cache-control'));

        $content = (string) $this->client->getResponse()->getContent();
        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('a@example.com', $data['account']['email']);
        self::assertSame('Lyon (Rhône, France)', $data['household']['city']);
        self::assertCount(28, $data['household']['targets']);
        self::assertCount(1, $data['places']);
        self::assertSame('Salon', $data['places'][0]['name']);
        self::assertSame([['measured_at' => '2026-10-08 08:00', 'outdoor_temperature' => 5.0, 'indoor_temperature' => 15.0]], $data['places'][0]['readings']);
        self::assertSame(21.0, $data['places'][0]['heating_starts'][0]['setpoint']);
        self::assertStringNotContainsString('autre@example.com', $content);
        self::assertStringNotContainsString('Chambre', $content);
        self::assertStringNotContainsString('password', $content);
    }

    public function testDeletionPageIsReachableDuringSetup(): void
    {
        $user = (new User())->setEmail('neuf@example.com')->setVerified(true)->setPassword('x');
        $this->em->persist($user);
        $this->em->persist(new Household($user));
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/compte/supprimer');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Supprimer mon compte');
    }

    public function testAccountPageOffersBothActions(): void
    {
        $this->client->loginUser($this->createUser('a@example.com', 'Salon'));

        $this->client->request('GET', '/compte');

        self::assertSelectorExists('a[href="/compte/export"]');
        self::assertSelectorExists('a[href="/compte/supprimer"]');
    }

    public function testDeletionRemovesEverythingOfTheAccountAndOnlyIt(): void
    {
        $user = $this->createUser('a@example.com', 'Salon');
        $other = $this->createUser('autre@example.com', 'Chambre');
        $this->client->loginUser($user);

        $this->client->request('GET', '/compte/supprimer');
        $this->client->submitForm('Supprimer définitivement mon compte', [
            'delete_account_form[currentPassword]' => self::PASSWORD,
            'delete_account_form[confirm]' => '1',
        ]);

        self::assertResponseRedirects('/');
        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'a@example.com']));
        self::assertCount(1, $this->em->getRepository(User::class)->findBy(['email' => 'autre@example.com']));
        self::assertSame(1, $this->em->getRepository(Household::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Place::class)->count([]), 'Les lieux du compte supprimé ont disparu.');
        self::assertSame(1, $this->em->getRepository(Reading::class)->count([]));
        self::assertSame(1, $this->em->getRepository(HeatingStart::class)->count([]));
        self::assertNotNull($other->getId());

        // Plus connecté.
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Votre compte et toutes vos données ont été supprimés');
        $this->client->request('GET', '/compte');
        self::assertResponseRedirects('/login');
    }

    public function testDeletionNeedsThePasswordAndTheConfirmation(): void
    {
        $this->client->loginUser($this->createUser('a@example.com', 'Salon'));

        $this->client->request('GET', '/compte/supprimer');
        $this->client->submitForm('Supprimer définitivement mon compte', [
            'delete_account_form[currentPassword]' => 'mauvais',
            'delete_account_form[confirm]' => '1',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le mot de passe actuel est incorrect.');

        $this->client->request('GET', '/compte/supprimer');
        $this->client->submitForm('Supprimer définitivement mon compte', [
            'delete_account_form[currentPassword]' => self::PASSWORD,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Cochez la case');

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(User::class)->findOneBy(['email' => 'a@example.com']));
    }

    public function testDeletionAttemptsAreThrottled(): void
    {
        $this->client->disableReboot();
        $this->client->loginUser($this->createUser('a@example.com', 'Salon'));

        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('GET', '/compte/supprimer');
            $this->client->submitForm('Supprimer définitivement mon compte', ['delete_account_form[currentPassword]' => 'mauvais '.$i]);
        }
        $this->client->request('GET', '/compte/supprimer');
        $this->client->submitForm('Supprimer définitivement mon compte', ['delete_account_form[currentPassword]' => self::PASSWORD, 'delete_account_form[confirm]' => '1']);

        self::assertResponseStatusCodeSame(429);
    }

    private function createUser(string $email, string $placeName): User
    {
        $user = (new User())->setEmail($email)->setVerified(true);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $household = (new Household($user))->completeSetup(new \DateTimeImmutable('2026-01-01'));
        $household->locate('Lyon (Rhône, France)', 45.75, 4.85, 'Europe/Paris');
        $place = new Place($household, $placeName);
        $this->em->persist($user);
        $this->em->persist($household);
        $this->em->persist($place);
        $this->em->persist(new Reading($place, new \DateTimeImmutable('2026-10-08 08:00'), 5.0, 15.0));
        $this->em->persist(new HeatingStart($place, new \DateTimeImmutable('2026-10-08 08:05'), 21.0, 15.0, 5.0));
        $this->em->flush();

        return $user;
    }
}
