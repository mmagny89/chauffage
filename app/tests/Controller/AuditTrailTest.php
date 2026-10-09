<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Household;
use App\Entity\User;
use App\Security\AuditLogger;
use App\Tests\Support\ResetsRateLimiters;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les événements de sécurité atteignent le canal d'audit, sans adresse email.
 */
final class AuditTrailTest extends WebTestCase
{
    use ResetsRateLimiters;

    private const EMAIL = 'famille@example.com';
    private const PASSWORD = 'cheval agrafeuse nuage tricot';

    private KernelBrowser $client;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Le gestionnaire de test vit dans le conteneur : un redémarrage entre deux requêtes le viderait.
        $this->client->disableReboot();
        self::resetRateLimiters();
    }

    public function testFailedLoginIsAuditedWithoutTheEmail(): void
    {
        $this->createUser(verified: true);

        $this->login(self::EMAIL, 'mauvais mot de passe');

        $record = $this->single('login_failed');
        self::assertSame('WARNING', $record['level']);
        self::assertSame(AuditLogger::fingerprint(self::EMAIL), $record['context']['identifier']);
        self::assertSame('BadCredentialsException', $record['context']['reason']);
        self::assertArrayHasKey('ip', $record['context']);
        $this->assertNoEmailInLogs();
    }

    public function testUnverifiedAccountLoginFailureIsDistinguishable(): void
    {
        $this->createUser(verified: false);

        $this->login(self::EMAIL, self::PASSWORD);

        self::assertSame('CustomUserMessageAccountStatusException', $this->single('login_failed')['context']['reason']);
    }

    public function testThrottledLoginIsAudited(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $this->login(self::EMAIL, 'tentative '.$i);
        }

        $reasons = array_map(static fn (array $r): string => (string) $r['context']['reason'], $this->records('login_failed'));
        self::assertContains('TooManyLoginAttemptsAuthenticationException', $reasons);
    }

    public function testSuccessfulLoginIsAuditedWithTheInternalId(): void
    {
        $user = $this->createUser(verified: true);

        $this->login(self::EMAIL, self::PASSWORD);

        $record = $this->single('login_succeeded');
        self::assertSame('INFO', $record['level']);
        self::assertSame($user->getId(), $record['context']['user_id']);
        $this->assertNoEmailInLogs();
    }

    public function testRegistrationAndEmailVerificationAreAudited(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Créer mon compte', [
            'registration_form[email]' => self::EMAIL,
            'registration_form[plainPassword]' => self::PASSWORD,
        ]);
        $this->drain();
        self::assertNotEmpty($this->records('registered'));

        $message = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $message);
        self::assertSame(1, preg_match('#href="(https?://[^"]+)"#', (string) $message->getHtmlBody(), $matches));
        $url = parse_url(html_entity_decode($matches[1]));
        $this->client->request('GET', ($url['path'] ?? '/').'?'.($url['query'] ?? ''));

        self::assertNotEmpty($this->records('email_verified'));
        $this->assertNoEmailInLogs();
    }

    public function testRegistrationRateLimitIsAudited(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->client->request('GET', '/register');
            $this->client->submitForm('Créer mon compte', [
                'registration_form[email]' => \sprintf('nouveau%d@example.com', $i),
                'registration_form[plainPassword]' => self::PASSWORD,
            ]);
            $this->drain();
        }

        $limited = $this->records('rate_limited');
        self::assertNotEmpty($limited);
        self::assertSame('registration', $limited[0]['context']['limiter']);
    }

    public function testPasswordResetRequestIsAuditedWithoutRevealingTheAccountToTheVisitor(): void
    {
        $this->createUser(verified: true);

        foreach ([self::EMAIL, 'inconnu@example.com'] as $email) {
            $this->client->request('GET', '/reset-password');
            $this->client->submitForm('Envoyer le lien', ['reset_password_request_form[email]' => $email]);
            self::assertResponseRedirects('/reset-password/check-email', null, 'Même réponse pour un compte connu ou inconnu.');
            $this->drain();
        }

        $requests = $this->records('password_reset_requested');
        self::assertCount(2, $requests);
        self::assertTrue($requests[0]['context']['known_account']);
        self::assertFalse($requests[1]['context']['known_account'], 'Seul le journal sait la différence.');
        $this->assertNoEmailInLogs();
    }

    private function createUser(bool $verified): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail(self::EMAIL)->setVerified($verified);
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->persist(new Household($user));
        $em->flush();

        return $user;
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => $email, '_password' => $password]);
        $this->drain();
    }

    /**
     * Symfony réinitialise les gestionnaires de log entre deux requêtes : le test cumule donc
     * les enregistrements après chacune.
     */
    private function drain(): void
    {
        $handler = self::getContainer()->get('test.audit_handler');
        \assert($handler instanceof TestHandler);

        foreach ($handler->getRecords() as $record) {
            $this->log[] = ['level' => strtoupper($record->level->name), 'message' => $record->message, 'context' => $record->context];
        }
        $handler->clear();
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function records(string $message): array
    {
        $this->drain();

        return array_values(array_filter($this->log, static fn (array $r): bool => $r['message'] === $message));
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function single(string $message): array
    {
        $records = $this->records($message);
        self::assertCount(1, $records, \sprintf('Un événement « %s » attendu.', $message));

        return $records[0];
    }

    private function assertNoEmailInLogs(): void
    {
        $this->drain();

        foreach ($this->log as $record) {
            self::assertStringNotContainsString('@', json_encode([$record['message'], $record['context']], \JSON_THROW_ON_ERROR), 'Aucune adresse email dans le journal d’audit.');
        }
    }
}
