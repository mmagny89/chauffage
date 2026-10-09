<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Household;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\ResetsRateLimiters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountControllerTest extends WebTestCase
{
    use ResetsRateLimiters;

    private const CURRENT = 'cheval agrafeuse nuage tricot';
    private const NEW = 'lampe bureau fenetre tortue';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::resetRateLimiters();
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/compte');

        self::assertResponseRedirects('/login');
    }

    public function testPasswordIsChangedAndTheUserStaysSignedIn(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);

        $this->submit(self::CURRENT, self::NEW, self::NEW);

        self::assertResponseRedirects('/compte');
        // Le noyau a redémarré entre deux requêtes : on relit le compte en base.
        $fresh = self::getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($fresh, self::NEW));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=status]', 'Mot de passe modifié');
    }

    public function testWrongCurrentPasswordIsRefused(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);

        $this->submit('tout autre mot de passe', self::NEW, self::NEW);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le mot de passe actuel est incorrect.');
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::CURRENT));
    }

    public function testNewPasswordMustFollowThePolicyAndBeConfirmed(): void
    {
        $this->client->loginUser($this->createUser());

        $this->submit(self::CURRENT, 'court', 'court');
        self::assertSelectorTextContains('body', 'au moins 12 caractères');

        $this->submit(self::CURRENT, self::NEW, 'une autre suite de mots');
        self::assertSelectorTextContains('body', 'ne correspondent pas');
    }

    public function testAttemptsAreThrottled(): void
    {
        // L'état du limiteur vit en mémoire : sans cela, chaque requête redémarre le noyau et le remet à zéro.
        $this->client->disableReboot();
        $this->client->loginUser($this->createUser());

        for ($i = 0; $i < 5; ++$i) {
            $this->submit('mauvais mot de passe '.$i, self::NEW, self::NEW);
        }
        $this->submit(self::CURRENT, self::NEW, self::NEW);

        self::assertResponseStatusCodeSame(429);
    }

    private function submit(string $current, string $new, string $confirmation): void
    {
        $this->client->request('GET', '/compte');
        $this->client->submitForm('Enregistrer', [
            'change_password_form[currentPassword]' => $current,
            'change_password_form[plainPassword][first]' => $new,
            'change_password_form[plainPassword][second]' => $confirmation,
        ]);
    }

    private function createUser(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('compte@example.com')->setVerified(true);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::CURRENT));
        $household = (new Household($user))->completeSetup(new \DateTimeImmutable('2026-01-01'));
        $em->persist($user);
        $em->persist($household);
        $em->flush();

        return $user;
    }
}
