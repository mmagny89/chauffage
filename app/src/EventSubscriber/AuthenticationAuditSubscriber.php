<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Journalise chaque tentative de connexion, réussie ou non.
 */
final readonly class AuthenticationAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onFailure',
            LoginSuccessEvent::class => 'onSuccess',
        ];
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        $badge = $event->getPassport()?->getBadge(UserBadge::class);

        $this->audit->warning('login_failed', [
            'identifier' => $badge instanceof UserBadge ? AuditLogger::fingerprint($badge->getUserIdentifier()) : null,
            'reason' => (new \ReflectionClass($event->getException()))->getShortName(),
        ]);
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        $this->audit->info('login_succeeded', ['user_id' => $user instanceof User ? $user->getId() : null]);
    }
}
