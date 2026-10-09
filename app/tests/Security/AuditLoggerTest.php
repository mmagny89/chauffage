<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\EventSubscriber\AuthenticationAuditSubscriber;
use App\Security\AuditLogger;
use App\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class AuditLoggerTest extends TestCase
{
    public function testFingerprintIsStableShortAndHidesTheIdentifier(): void
    {
        $fingerprint = AuditLogger::fingerprint('Famille@Example.com');

        self::assertSame($fingerprint, AuditLogger::fingerprint('  famille@example.com '), 'Insensible à la casse et aux espaces.');
        self::assertNotSame($fingerprint, AuditLogger::fingerprint('autre@example.com'));
        self::assertSame(12, \strlen($fingerprint));
        self::assertStringNotContainsString('famille', $fingerprint);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $fingerprint);
    }

    public function testRecordsCarryTheClientAddress(): void
    {
        $logger = new ArrayLogger();
        $audit = new AuditLogger($logger, $this->requests('203.0.113.7'));

        $audit->info('registered', ['user_id' => 42]);
        $audit->warning('rate_limited', ['limiter' => 'registration']);

        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame(['ip' => '203.0.113.7', 'user_id' => 42], $logger->records[0]['context']);
        self::assertSame('warning', $logger->records[1]['level']);
        self::assertSame('rate_limited', $logger->records[1]['message']);
    }

    public function testWorksOutsideOfARequest(): void
    {
        $logger = new ArrayLogger();

        (new AuditLogger($logger, new RequestStack()))->info('x');

        self::assertNull($logger->records[0]['context']['ip']);
    }

    public function testFailedLoginIsLoggedAsAWarningWithoutTheEmail(): void
    {
        $logger = new ArrayLogger();
        $subscriber = new AuthenticationAuditSubscriber(new AuditLogger($logger, $this->requests('198.51.100.9')));
        $passport = new SelfValidatingPassport(new UserBadge('famille@example.com'));

        $subscriber->onFailure(new LoginFailureEvent(new BadCredentialsException(), $this->createStub(AuthenticatorInterface::class), new Request(), null, 'main', $passport));

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('login_failed', $logger->records[0]['message']);
        self::assertSame(AuditLogger::fingerprint('famille@example.com'), $logger->records[0]['context']['identifier']);
        self::assertSame('BadCredentialsException', $logger->records[0]['context']['reason']);
        self::assertStringNotContainsString('famille@example.com', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    public function testSuccessfulLoginLogsTheInternalIdentifierOnly(): void
    {
        $logger = new ArrayLogger();
        $audit = new AuditLogger($logger, $this->requests('198.51.100.9'));
        $user = (new User())->setEmail('famille@example.com');

        $audit->info('login_succeeded', ['user_id' => $user->getId()]);

        self::assertStringNotContainsString('famille@example.com', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    private function requests(string $ip): RequestStack
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/', server: ['REMOTE_ADDR' => $ip]));

        return $stack;
    }
}
