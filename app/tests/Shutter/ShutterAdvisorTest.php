<?php

declare(strict_types=1);

namespace App\Tests\Shutter;

use App\Shutter\DailySun;
use App\Shutter\ShutterAdvisor;
use App\Shutter\ShutterPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShutterAdvisorTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, ShutterPlan}> moyenne (dixièmes), soleil (heures sur 10 h de jour), conseil
     */
    public static function days(): iterable
    {
        yield 'froid et ensoleillé' => [50, 8, ShutterPlan::OpenForSun];
        yield 'froid, soleil à la moitié exactement' => [50, 5, ShutterPlan::OpenForSun];
        yield 'froid, juste sous la moitié' => [50, 4, ShutterPlan::StayClosed];
        yield 'froid et couvert' => [30, 0, ShutterPlan::StayClosed];
        yield 'doux et ensoleillé' => [200, 9, ShutterPlan::Mild];
        yield 'à la limite : plus assez froid' => [ShutterAdvisor::COLD_BELOW_TENTHS, 9, ShutterPlan::Mild];
        yield 'juste sous la limite' => [ShutterAdvisor::COLD_BELOW_TENTHS - 1, 9, ShutterPlan::OpenForSun];
    }

    #[DataProvider('days')]
    public function testAdvice(int $meanTenths, int $sunHours, ShutterPlan $expected): void
    {
        $advice = (new ShutterAdvisor())->advise($this->day($meanTenths, $sunHours * 3600));

        self::assertSame($expected, $advice->plan);
        self::assertSame('07:00', $advice->sunrise->format('H:i'));
        self::assertSame('17:00', $advice->sunset->format('H:i'));
        self::assertSame($sunHours * 60, $advice->sunshineMinutes);
    }

    public function testADayWithoutDaylightNeverCountsAsSunny(): void
    {
        $day = new DailySun(new \DateTimeImmutable('2026-12-21'), new \DateTimeImmutable('2026-12-21 12:00'), new \DateTimeImmutable('2026-12-21 12:00'), 0, 0);

        self::assertSame(ShutterPlan::StayClosed, (new ShutterAdvisor())->advise($day)->plan);
    }

    private function day(int $meanTenths, int $sunshineSeconds): DailySun
    {
        $date = new \DateTimeImmutable('2026-12-01');

        return new DailySun($date, $date->setTime(7, 0), $date->setTime(17, 0), $sunshineSeconds, $meanTenths);
    }
}
