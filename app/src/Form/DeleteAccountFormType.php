<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class DeleteAccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new NotBlank(message: 'Saisissez votre mot de passe actuel.'),
                    new UserPassword(message: 'Le mot de passe actuel est incorrect.'),
                ],
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Je comprends que mon compte et toutes mes données seront supprimés définitivement',
                'mapped' => false,
                'constraints' => [new IsTrue(message: 'Cochez la case pour confirmer la suppression.')],
            ]);
    }
}
