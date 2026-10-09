<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Security\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AccountController extends AbstractController
{
    #[Route('/compte', name: 'app_account')]
    public function index(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $entityManager,
        Security $security,
        RateLimiterFactoryInterface $passwordChangeLimiter,
        AuditLogger $audit,
        #[CurrentUser] User $user,
    ): Response {
        $form = $this->createForm(ChangePasswordFormType::class, null, ['ask_current_password' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Chaque tentative compte, réussie ou non : ce formulaire permet de tester un mot de passe actuel.
            $limit = $passwordChangeLimiter->create((string) $user->getId())->consume();
            if (!$limit->isAccepted()) {
                $audit->warning('rate_limited', ['limiter' => 'password_change', 'user_id' => $user->getId()]);

                throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time());
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($hasher->hashPassword($user, $plainPassword));
            $entityManager->flush();

            $audit->info('password_changed', ['user_id' => $user->getId()]);
            // Le hachage fait partie de l'identité en session : sans nouvelle connexion, l'utilisateur serait déconnecté
            // (et l'ancienne session, invalidée — c'est voulu).
            $security->login($user, 'form_login', 'main');
            $this->addFlash('success', 'Mot de passe modifié.');

            return $this->redirectToRoute('app_account');
        }

        if ($form->isSubmitted()) {
            $audit->warning('password_change_refused', ['user_id' => $user->getId()]);
        }

        return $this->render('account/index.html.twig', [
            'form' => $form,
            'email' => $user->getUserIdentifier(),
        ]);
    }
}
