<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DaySlot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DaySlotTest extends TestCase
{
    #[DataProvider('hours')]
    public function testFromHour(int $hour, DaySlot $expected): void
    {
        self::assertSame($expected, DaySlot::fromHour($hour));
    }

    /**
     * @return iterable<string, array{int, DaySlot}>
     */
    public static function hours(): iterable
    {
        yield 'minuit' => [0, DaySlot::Night];
        yield '5 h' => [5, DaySlot::Night];
        yield '6 h' => [6, DaySlot::Morning];
        yield '11 h' => [11, DaySlot::Morning];
        yield '12 h' => [12, DaySlot::Afternoon];
        yield '17 h' => [17, DaySlot::Afternoon];
        yield '18 h' => [18, DaySlot::Evening];
        yield '21 h' => [21, DaySlot::Evening];
        yield '22 h' => [22, DaySlot::Night];
        yield '23 h' => [23, DaySlot::Night];
    }

    public function testEveryHourBelongsToExactlyOneSlot(): void
    {
        $counts = array_fill_keys(array_column(DaySlot::cases(), 'value'), 0);
        for ($hour = 0; $hour < 24; ++$hour) {
            ++$counts[DaySlot::fromHour($hour)->value];
        }

        self::assertSame(['night' => 8, 'morning' => 6, 'afternoon' => 6, 'evening' => 4], $counts);
    }

    public function testInvalidHourIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DaySlot::fromHour(24);
    }

    public function testFromDateTimeUsesWallClockHour(): void
    {
        self::assertSame(DaySlot::Evening, DaySlot::fromDateTime(new \DateTimeImmutable('2026-10-09 19:30')));
    }
}
