<?php

declare(strict_types=1);

namespace App\Form;

use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ChangePasswordFormType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        // Depuis « Mon compte », l'utilisateur est connecté mais doit prouver qu'il connaît son mot de passe actuel.
        $resolver->setDefault('ask_current_password', false);
        $resolver->setAllowedTypes('ask_current_password', 'bool');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['ask_current_password']) {
            $builder->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new NotBlank(message: 'Saisissez votre mot de passe actuel.'),
                    new UserPassword(message: 'Le mot de passe actuel est incorrect.'),
                ],
            ]);
        }

        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'options' => ['attr' => ['autocomplete' => 'new-password', 'minlength' => PasswordPolicy::MIN_LENGTH]],
            'first_options' => [
                'label' => 'Nouveau mot de passe',
                'help' => PasswordPolicy::HELP,
                'constraints' => PasswordPolicy::constraints(),
            ],
            'second_options' => ['label' => 'Confirmez le mot de passe'],
            'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
            'mapped' => false,
        ]);
    }
}
