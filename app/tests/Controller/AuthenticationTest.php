<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\HouseholdRepository;
use App\Repository\UserRepository;
use App\Tests\Support\ResetsRateLimiters;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class AuthenticationTest extends WebTestCase
{
    use ResetsRateLimiters;

    private const EMAIL = 'famille@example.com';
    private const PASSWORD = 'cheval agrafeuse nuage tricot';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::resetRateLimiters();
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testCsrfFieldsAreWiredToTheStimulusController(): void
    {
        // Jetons stateless : sans ce contrôleur, le navigateur envoie le marqueur brut et le serveur répond « Jeton CSRF invalide ».
        foreach (['/login' => 'input[name=_csrf_token][data-controller=csrf-protection]', '/register' => 'input[name="registration_form[_token]"][data-controller=csrf-protection]'] as $path => $selector) {
            $this->client->request('GET', $path);
            self::assertSelectorExists($selector, $path);
        }
    }

    public function testRegistrationVerificationAndLogin(): void
    {
        $this->register(self::EMAIL, self::PASSWORD);
        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
        $confirmationLink = $this->linkFromEmail();

        $user = $this->requireUser(self::EMAIL);
        self::assertFalse($user->isVerified());

        $household = self::getContainer()->get(HouseholdRepository::class)->findOneBy(['user' => $user]);
        self::assertNotNull($household, 'Un compte reçoit son foyer dès l’inscription.');
        self::assertCount(28, $household->getTargets());
        self::assertNotSame(self::PASSWORD, $user->getPassword());

        // Mot de passe juste, adresse non confirmée : refusé.
        $this->login(self::EMAIL, self::PASSWORD);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Confirmez votre adresse email');

        $this->client->request('GET', $confirmationLink);
        self::assertResponseRedirects('/login');
        self::assertTrue($this->requireUser(self::EMAIL)->isVerified());

        $this->login(self::EMAIL, self::PASSWORD);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        // Premier accès : on règle d'abord (ville, lieux, températures visées), on relève ensuite.
        self::assertResponseRedirects('/reglages');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Mise en route');
        self::assertSelectorTextContains('[role=status]', 'Avant de saisir des relevés, terminez la mise en route');
    }

    public function testWrongPasswordGivesGenericError(): void
    {
        $this->register(self::EMAIL, self::PASSWORD);
        $this->client->request('GET', $this->linkFromEmail());

        $this->login(self::EMAIL, 'tout autre mot de passe');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Identifiants invalides');

        $this->login('inconnu@example.com', 'tout autre mot de passe');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Identifiants invalides');
    }

    public function testWeakPasswordIsRejectedAtRegistration(): void
    {
        $this->register(self::EMAIL, '123456789012');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findUser(self::EMAIL));
        self::assertEmailCount(0);
    }

    public function testEmailIsUnique(): void
    {
        $this->register(self::EMAIL, self::PASSWORD);
        $this->register(strtoupper(self::EMAIL), self::PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('ul', 'Un compte existe déjà');
    }

    public function testPasswordResetFlow(): void
    {
        $this->register(self::EMAIL, self::PASSWORD);
        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Envoyer le lien', ['reset_password_request_form[email]' => self::EMAIL]);
        self::assertResponseRedirects('/reset-password/check-email');
        self::assertEmailCount(1);

        $this->client->request('GET', $this->linkFromEmail());
        $this->client->followRedirect();
        $this->client->submitForm('Enregistrer', [
            'change_password_form[plainPassword][first]' => 'lampe bureau fenetre tortue',
            'change_password_form[plainPassword][second]' => 'lampe bureau fenetre tortue',
        ]);
        self::assertResponseRedirects('/login');

        // La réinitialisation prouve la possession de l'adresse : le compte est confirmé.
        self::assertTrue($this->requireUser(self::EMAIL)->isVerified());

        $this->login(self::EMAIL, self::PASSWORD);
        $this->client->followRedirect();
        self::assertSelectorExists('[role=alert]');

        $this->login(self::EMAIL, 'lampe bureau fenetre tortue');
        self::assertResponseRedirects('/');
    }

    public function testResetRequestDoesNotRevealUnknownAddress(): void
    {
        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Envoyer le lien', ['reset_password_request_form[email]' => 'inconnu@example.com']);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertEmailCount(0);
    }

    public function testLoginIsThrottled(): void
    {
        // L'état du limiteur vit en mémoire : sans cela, chaque requête redémarre le noyau et le remet à zéro.
        $this->client->disableReboot();

        for ($i = 0; $i < 6; ++$i) {
            $this->login(self::EMAIL, 'mauvais mot de passe '.$i);
            $this->client->followRedirect();
        }

        self::assertSelectorTextContains('[role=alert]', 'Trop de tentatives');
    }

    private function register(string $email, string $password): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Créer mon compte', [
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => $password,
        ]);
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => $email, '_password' => $password]);
    }

    private function findUser(string $email): ?User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => mb_strtolower($email)]);
    }

    private function requireUser(string $email): User
    {
        return $this->findUser($email) ?? throw new \LogicException(\sprintf('Compte « %s » introuvable.', $email));
    }

    private function linkFromEmail(int $index = 0): string
    {
        $message = self::getMailerMessages()[$index];
        self::assertInstanceOf(Email::class, $message);
        self::assertSame(1, preg_match('#href="(https?://[^"]+)"#', (string) $message->getHtmlBody(), $matches));
        $url = parse_url(html_entity_decode($matches[1]));

        return ($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');
    }
}
