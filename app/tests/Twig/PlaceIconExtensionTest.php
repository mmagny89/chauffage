<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\RoomCatalog;
use App\Twig\PlaceIconExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlaceIconExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'chambre' => ['Chambre d’enfant', 'bed'];
        yield 'majuscules' => ['SALON', 'sofa'];
        yield 'accent' => ['Entrée', 'door'];
        yield 'sans accent' => ['entree', 'door'];
        yield 'salle de bain avant bain' => ['Salle de bain', 'bath'];
        yield 'salle à manger' => ['Salle à manger', 'utensils'];
        yield 'inconnu' => ['Atelier', 'home'];
    }

    #[DataProvider('names')]
    public function testIconIsChosenFromTheName(string $name, string $icon): void
    {
        self::assertSame($icon, (new PlaceIconExtension())->iconFor($name));
    }

    public function testEveryCataloguedRoomHasItsOwnIcon(): void
    {
        $extension = new PlaceIconExtension();
        foreach (RoomCatalog::ROOMS as $room) {
            self::assertNotSame('home', $extension->iconFor($room), $room);
        }
    }
}
