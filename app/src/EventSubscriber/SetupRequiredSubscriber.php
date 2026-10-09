<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\HouseholdProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Tant que la mise en route d'un foyer n'est pas terminée (ville, lieux, températures visées),
 * toute page autre que les réglages renvoie vers les réglages : on règle d'abord, on relève ensuite.
 *
 * La requête reçoit l'attribut « setup_pending » pour que les gabarits n'affichent pas de menu
 * vers des pages inaccessibles.
 */
final readonly class SetupRequiredSubscriber implements EventSubscriberInterface
{
    public const PENDING_ATTRIBUTE = 'setup_pending';

    /** Routes accessibles pendant la mise en route : les réglages eux-mêmes et la déconnexion. */
    private const ALLOWED_ROUTES = [
        'app_settings',
        'app_settings_city',
        'app_place_add',
        'app_place_rename',
        'app_place_delete',
        'app_setup_finish',
        'app_account',
        'app_logout',
    ];

    public function __construct(
        private TokenStorageInterface $tokens,
        private HouseholdProvider $households,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Après le pare-feu (priorité 8) : l'utilisateur est connu ; après le routeur (32) : la route aussi.
        return [KernelEvents::REQUEST => ['onRequest', 0]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!$event->isMainRequest() || !\is_string($route) || str_starts_with($route, '_')) {
            return;
        }

        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($this->households->forUser($user)->isSetUp()) {
            return;
        }

        $request->attributes->set(self::PENDING_ATTRIBUTE, true);
        if (\in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('info', 'Avant de saisir des relevés, terminez la mise en route : votre ville, vos lieux et les températures que vous visez.');
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('app_settings')));
    }
}
