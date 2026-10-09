<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Politique de sécurité du contenu (CSP) avec un nonce par requête.
 *
 * Le nonce est toujours généré et exposé aux gabarits (`csp_nonce`) ; l'en-tête n'est
 * posé que hors mode debug (la barre du profileur de Symfony injecte ses propres scripts),
 * ou si APP_CSP est vrai, pour l'éprouver en développement.
 *
 * Les en-têtes sans nonce (X-Frame-Options, Referrer-Policy…) sont posés par le Caddyfile.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public const NONCE_ATTRIBUTE = 'csp_nonce';

    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
        #[Autowire('%env(bool:APP_CSP)%')]
        private readonly bool $forced,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['generateNonce', 256],
            KernelEvents::RESPONSE => 'addPolicy',
        ];
    }

    public function generateNonce(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::NONCE_ATTRIBUTE, base64_encode(random_bytes(16)));
        }
    }

    public function addPolicy(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || ($this->debug && !$this->forced)) {
            return;
        }

        $nonce = $event->getRequest()->attributes->get(self::NONCE_ATTRIBUTE);
        if (!\is_string($nonce)) {
            return;
        }

        $event->getResponse()->headers->set('Content-Security-Policy', self::policy($nonce));
    }

    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-".$nonce."'",
            "style-src 'self' 'nonce-".$nonce."'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
