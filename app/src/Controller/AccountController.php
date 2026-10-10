<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\DeleteAccountFormType;
use App\Security\AuditLogger;
use App\Service\AccountExporter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    /**
     * Télécharge toutes les données du compte (droit d'accès et à la portabilité).
     */
    #[Route('/compte/export', name: 'app_account_export', methods: ['GET'])]
    public function export(AccountExporter $exporter, AuditLogger $audit, ClockInterface $clock, #[CurrentUser] User $user): Response
    {
        // Les options d'encodage se posent avant les données : les changer après décode et ré-encode (5.0 redeviendrait 5).
        $response = new JsonResponse();
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        $response->setData($exporter->export($user));
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'chauffage-donnees-'.$clock->now()->format('Y-m-d').'.json'));
        $response->headers->set('Cache-Control', 'private, no-store');

        $audit->info('account_data_exported', ['user_id' => $user->getId()]);

        return $response;
    }

    /**
     * Supprime le compte et, par cascade en base, le foyer, les lieux, les relevés et les allumages (droit
     * à l'effacement). Mot de passe exigé et confirmation cochée : l'action est irréversible.
     */
    #[Route('/compte/supprimer', name: 'app_account_delete', methods: ['GET', 'POST'])]
    public function delete(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        RateLimiterFactoryInterface $accountDeleteLimiter,
        AuditLogger $audit,
        #[CurrentUser] User $user,
    ): Response {
        $form = $this->createForm(DeleteAccountFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Comme le changement de mot de passe, ce formulaire permet de tester un mot de passe actuel.
            $limit = $accountDeleteLimiter->create((string) $user->getId())->consume();
            if (!$limit->isAccepted()) {
                $audit->warning('rate_limited', ['limiter' => 'account_delete', 'user_id' => $user->getId()]);

                throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time());
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $userId = $user->getId();
            $entityManager->remove($user);
            $entityManager->flush();
            $audit->info('account_deleted', ['user_id' => $userId]);

            // Plus de session : l'utilisateur n'existe plus.
            $security->logout(false);
            $this->addFlash('success', 'Votre compte et toutes vos données ont été supprimés.');

            return $this->redirectToRoute('app_home');
        }

        if ($form->isSubmitted()) {
            $audit->warning('account_delete_refused', ['user_id' => $user->getId()]);
        }

        return $this->render('account/delete.html.twig', ['form' => $form]);
    }
}
