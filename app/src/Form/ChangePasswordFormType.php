<?php

declare(strict_types=1);

namespace App\Form;

use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
