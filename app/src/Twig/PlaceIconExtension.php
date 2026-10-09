<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\String\UnicodeString;
use Twig\Attribute\AsTwigFunction;

/**
 * Choisit l'icône d'une pièce d'après son nom (« Chambre d’enfant » → lit). Le nom est libre :
 * sans correspondance, l'icône par défaut est une maison.
 */
final class PlaceIconExtension
{
    private const DEFAULT = 'home';

    /** Mot cherché dans le nom (sans accent, en minuscules) => nom de l'icône ; le premier qui correspond gagne. */
    private const KEYWORDS = [
        'chambre' => 'bed',
        'salle de bain' => 'bath',
        'bain' => 'bath',
        'douche' => 'bath',
        'toilette' => 'droplet',
        'wc' => 'droplet',
        'salle a manger' => 'utensils',
        'cuisine' => 'utensils',
        'salon' => 'sofa',
        'sejour' => 'sofa',
        'bureau' => 'briefcase',
        'entree' => 'door',
        'couloir' => 'corridor',
        'escalier' => 'stairs',
        'buanderie' => 'washer',
        'veranda' => 'sun',
        'garage' => 'car',
        'cave' => 'box',
        'grenier' => 'attic',
        'comble' => 'attic',
    ];

    #[AsTwigFunction('place_icon')]
    public function iconFor(string $placeName): string
    {
        $name = (new UnicodeString($placeName))->ascii()->lower()->toString();
        foreach (self::KEYWORDS as $keyword => $icon) {
            if (str_contains($name, $keyword)) {
                return $icon;
            }
        }

        return self::DEFAULT;
    }
}
