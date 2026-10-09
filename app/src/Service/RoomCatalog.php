<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Les lieux proposés dans la liste déroulante : les pièces usuelles d'une maison,
 * les lieux déjà utilisés par le foyer, et « Autre » pour un lieu qui n'y figure pas.
 */
final class RoomCatalog
{
    /** Valeur de l'option « Autre lieu… » : le nom se saisit alors dans un champ libre. */
    public const OTHER = '__other__';

    public const ROOMS = [
        'Entrée',
        'Salon',
        'Salle à manger',
        'Cuisine',
        'Bureau',
        'Chambre parentale',
        'Chambre d’enfant',
        'Chambre d’amis',
        'Salle de bain',
        'Toilettes',
        'Buanderie',
        'Couloir',
        'Escalier',
        'Véranda',
        'Garage',
        'Cave',
        'Grenier',
        'Combles',
    ];

    /**
     * Choix pour un ChoiceType groupé (libellé => valeur).
     *
     * @param list<string> $householdPlaces lieux déjà enregistrés pour le foyer
     *
     * @return array<string, array<string, string>>
     */
    public function choices(array $householdPlaces): array
    {
        $known = array_map(mb_strtolower(...), self::ROOMS);
        $own = array_values(array_filter(
            $householdPlaces,
            static fn (string $name): bool => !\in_array(mb_strtolower($name), $known, true),
        ));

        $groups = ['Pièces' => array_combine(self::ROOMS, self::ROOMS)];
        if ([] !== $own) {
            $groups['Vos autres lieux'] = array_combine($own, $own);
        }
        $groups['Autre'] = ['Autre lieu…' => self::OTHER];

        return $groups;
    }
}
