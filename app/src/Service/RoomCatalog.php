<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Les pièces usuelles d'une maison proposées à l'ajout d'un lieu, et « Autre lieu… »
 * pour un lieu qui n'y figure pas.
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
     * Choix de la liste déroulante d'ajout d'un lieu : les pièces usuelles pas encore
     * déclarées, puis « Autre lieu… ».
     *
     * @param list<string> $declared noms des lieux déjà déclarés par le foyer
     *
     * @return array<string, array<string, string>> groupe => libellé => valeur
     */
    public function availableChoices(array $declared): array
    {
        $taken = array_map(mb_strtolower(...), $declared);
        $rooms = array_values(array_filter(
            self::ROOMS,
            static fn (string $room): bool => !\in_array(mb_strtolower($room), $taken, true),
        ));

        $groups = [];
        if ([] !== $rooms) {
            $groups['Pièces'] = array_combine($rooms, $rooms);
        }
        $groups['Autre'] = ['Autre lieu…' => self::OTHER];

        return $groups;
    }
}
