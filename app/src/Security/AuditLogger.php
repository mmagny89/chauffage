<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Journal des événements de sécurité (OWASP A09) : connexions, inscriptions,
 * réinitialisations, limites atteintes.
 *
 * Canal « audit » : en production il est écrit tel quel sur stderr, sans passer par le
 * tampon « fingers_crossed » qui ne garde que les erreurs et ferait disparaître ces
 * événements. Aucune adresse email ni mot de passe : un identifiant interne, ou une
 * empreinte courte de l'identifiant saisi pour relier des tentatives entre elles.
 */
final readonly class AuditLogger
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.audit')]
        private LoggerInterface $logger,
        private RequestStack $requests,
    ) {
    }

    /**
     * Empreinte courte et stable d'un identifiant saisi (jamais l'adresse elle-même).
     */
    public static function fingerprint(string $identifier): string
    {
        return substr(hash('sha256', mb_strtolower(trim($identifier))), 0, 12);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->logger->info($event, $this->withClient($context));
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->logger->warning($event, $this->withClient($context));
    }

    /**
     * @param array<string, scalar|null> $context
     *
     * @return array<string, scalar|null>
     */
    private function withClient(array $context): array
    {
        return ['ip' => $this->requests->getMainRequest()?->getClientIp()] + $context;
    }
}
