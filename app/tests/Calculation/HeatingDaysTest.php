<?php

declare(strict_types=1);

namespace App\Tests\Calculation;

use App\Calculation\HeatingDays;
use PHPUnit\Framework\TestCase;

final class HeatingDaysTest extends TestCase
{
    public function testKnowsWhichPlacesWereHeatedOnWhichDay(): void
    {
        $days = new HeatingDays(['2026-10-09' => ['salon', 'cave']]);

        self::assertTrue($days->has('2026-10-09'), 'Foyer : une pièce quelconque.');
        self::assertTrue($days->has('2026-10-09', 'Salon'), 'Casse ignorée.');
        self::assertFalse($days->has('2026-10-09', 'Chambre'));
        self::assertFalse($days->has('2026-10-10'));
        self::assertFalse((new HeatingDays())->has('2026-10-09'));
    }
}
