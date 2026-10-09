<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;

/**
 * Source unique des exigences de mot de passe : inscription et réinitialisation
 * l'appellent toutes deux, pour qu'aucune copie plus laxiste ne devienne la vraie.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const HELP = '12 caractères minimum. Préférez une suite de mots sans rapport entre eux à la recette majuscule, chiffre, symbole. Les mots de passe présents dans des fuites connues sont refusés.';

    /**
     * @return list<Constraint>
     */
    public static function constraints(): array
    {
        return [
            new NotBlank(message: 'Choisissez un mot de passe.'),
            new Length(
                min: self::MIN_LENGTH,
                max: 4096,
                minMessage: 'Le mot de passe doit comporter au moins {{ limit }} caractères.',
            ),
            new PasswordStrength(
                minScore: PasswordStrength::STRENGTH_MEDIUM,
                message: 'Ce mot de passe est trop facile à deviner.',
            ),
            new NotCompromisedPassword(message: 'Ce mot de passe figure dans une fuite de données connue : choisissez-en un autre.'),
        ];
    }
}
