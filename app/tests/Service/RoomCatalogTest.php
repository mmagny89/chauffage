<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RoomCatalog;
use PHPUnit\Framework\TestCase;

final class RoomCatalogTest extends TestCase
{
    public function testGroupsRoomsOwnPlacesAndOther(): void
    {
        $choices = (new RoomCatalog())->choices(['Salon', 'atelier', 'Cave', 'Salle de jeux']);

        self::assertSame(['Pièces', 'Vos autres lieux', 'Autre'], array_keys($choices));
        self::assertSame(['atelier' => 'atelier', 'Salle de jeux' => 'Salle de jeux'], $choices['Vos autres lieux'], 'Un lieu déjà dans la liste standard n’est pas répété.');
        self::assertSame(['Autre lieu…' => RoomCatalog::OTHER], $choices['Autre']);
        self::assertContains('Salon', $choices['Pièces']);
    }

    public function testOwnPlacesGroupIsOmittedWhenEmpty(): void
    {
        self::assertSame(['Pièces', 'Autre'], array_keys((new RoomCatalog())->choices([])));
    }

    public function testStandardRoomMatchIsCaseInsensitive(): void
    {
        $choices = (new RoomCatalog())->choices(['salon', 'CAVE']);

        self::assertArrayNotHasKey('Vos autres lieux', $choices);
    }
}
