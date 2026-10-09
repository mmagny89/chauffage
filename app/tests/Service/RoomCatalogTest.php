<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RoomCatalog;
use PHPUnit\Framework\TestCase;

final class RoomCatalogTest extends TestCase
{
    public function testOffersAllRoomsThenOtherWhenNothingIsDeclared(): void
    {
        $choices = (new RoomCatalog())->availableChoices([]);

        self::assertSame(['Pièces', 'Autre'], array_keys($choices));
        self::assertSame(RoomCatalog::ROOMS, array_keys($choices['Pièces']));
        self::assertSame(['Autre lieu…' => RoomCatalog::OTHER], $choices['Autre']);
    }

    public function testDeclaredRoomsAreNoLongerOfferedIgnoringCase(): void
    {
        $choices = (new RoomCatalog())->availableChoices(['salon', 'CAVE', 'Atelier']);

        self::assertArrayNotHasKey('Salon', $choices['Pièces']);
        self::assertArrayNotHasKey('Cave', $choices['Pièces']);
        self::assertArrayHasKey('Cuisine', $choices['Pièces']);
        self::assertCount(\count(RoomCatalog::ROOMS) - 2, $choices['Pièces'], 'Un lieu hors liste (Atelier) n’en retire aucun.');
    }

    public function testRoomsGroupDisappearsWhenEveryRoomIsDeclared(): void
    {
        $choices = (new RoomCatalog())->availableChoices(RoomCatalog::ROOMS);

        self::assertSame(['Autre'], array_keys($choices));
    }
}
