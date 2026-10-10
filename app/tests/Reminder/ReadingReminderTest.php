<?php

declare(strict_types=1);

namespace App\Tests\Reminder;

use App\Calculation\DeltaModel;
use App\Calculation\ModelKind;
use App\Enum\DaySlot;
use App\Forecast\DayForecast;
use App\Forecast\SlotForecast;
use App\Reminder\ReadingReminder;
use App\Reminder\Reminder;
use App\Reminder\ReminderReason;
use PHPUnit\Framework\TestCase;

final class ReadingReminderTest extends TestCase
{
    private const NOW = '2026-12-10 09:00';

    /** Modèle relevé entre 4 et 12 °C : couvre de 1 à 15 °C avec la marge. */
    private static function model(): DeltaModel
    {
        return new DeltaModel(ModelKind::Typical, 10.0, -0.4, 6, 3, 4.0, 12.0, 0.2);
    }

    /**
     * @return list<DayForecast>
     */
    private static function forecast(float $today, float $tomorrow): array
    {
        $day = static function (string $date, float $average): DayForecast {
            $slots = [];
            foreach (DaySlot::cases() as $slot) {
                $slots[$slot->value] = new SlotForecast($slot, $average, $average, $average, 4, 4);
            }

            return new DayForecast(new \DateTimeImmutable($date), $slots);
        };

        return [$day('2026-12-10', $today), $day('2026-12-11', $tomorrow)];
    }

    /**
     * @param list<DayForecast> $forecast
     */
    private function remind(int $daysDone, ?string $last, ?DeltaModel $model, array $forecast): ?Reminder
    {
        return (new ReadingReminder())->remind($daysDone, null === $last ? null : new \DateTimeImmutable($last), new \DateTimeImmutable(self::NOW), $model, $forecast);
    }

    public function testNothingToSuggestWithoutAnyReading(): void
    {
        self::assertNull($this->remind(0, null, null, self::forecast(5.0, 5.0)));
    }

    public function testNothingToSuggestWhenEverythingIsFine(): void
    {
        self::assertNull($this->remind(6, '2026-12-09 08:00', self::model(), self::forecast(8.0, 9.0)));
    }

    public function testForecastOutsideTheMeasuredRangeSuggestsAReading(): void
    {
        $reminder = $this->remind(6, '2026-12-09 08:00', self::model(), self::forecast(8.0, -6.0));

        self::assertNotNull($reminder);
        self::assertSame(ReminderReason::OutOfRange, $reminder->reason);
        self::assertSame(-6.0, $reminder->forecast);
        self::assertSame('2026-12-11', $reminder->date?->format('Y-m-d'));
        self::assertSame(4.0, $reminder->measuredMin);
        self::assertSame(12.0, $reminder->measuredMax);
    }

    public function testTheMarginIsTolerated(): void
    {
        // 4 - 3 = 1 °C et 12 + 3 = 15 °C sont encore couverts.
        self::assertNull($this->remind(6, '2026-12-09 08:00', self::model(), self::forecast(1.0, 15.0)));
        self::assertNotNull($this->remind(6, '2026-12-09 08:00', self::model(), self::forecast(0.9, 15.0)));
    }

    public function testTheMostExtremeOutOfRangeSlotIsChosen(): void
    {
        $reminder = $this->remind(6, '2026-12-09 08:00', self::model(), self::forecast(-1.0, 20.0));

        self::assertNotNull($reminder);
        self::assertSame(20.0, $reminder->forecast, '20 °C est à 5 °C de la plage, -1 °C à 2 °C seulement.');
    }

    public function testSlotsAlreadyOverDoNotCount(): void
    {
        // À 19 h, les créneaux d'aujourd'hui sauf la nuit sont passés ; la nuit d'aujourd'hui reste.
        $reminder = (new ReadingReminder())->remind(6, new \DateTimeImmutable('2026-12-09 08:00'), new \DateTimeImmutable('2026-12-11 13:00'), self::model(), self::forecast(-6.0, 8.0));

        self::assertNull($reminder, 'Hier soir et la nuit passée ne comptent plus.');
    }

    public function testNoForecastNoOutOfRangeReminder(): void
    {
        self::assertNull($this->remind(6, '2026-12-09 08:00', self::model(), []));
    }

    public function testStaleReading(): void
    {
        $reminder = $this->remind(6, '2026-12-03 08:00', self::model(), self::forecast(8.0, 8.0));

        self::assertNotNull($reminder);
        self::assertSame(ReminderReason::Stale, $reminder->reason);
        self::assertSame(7, $reminder->daysSince);
        self::assertNull($this->remind(6, '2026-12-04 23:00', self::model(), self::forecast(8.0, 8.0)), '6 jours : pas encore.');
    }

    public function testCalibrationInProgressWithoutReadingToday(): void
    {
        $reminder = $this->remind(2, '2026-12-09 20:00', self::model(), self::forecast(8.0, 8.0));

        self::assertNotNull($reminder);
        self::assertSame(ReminderReason::Calibration, $reminder->reason);
        self::assertSame(3, $reminder->daysMissing);
    }

    public function testCalibrationInProgressButReadingDoneToday(): void
    {
        self::assertNull($this->remind(2, '2026-12-10 07:00', self::model(), self::forecast(8.0, 8.0)));
    }

    public function testOutOfRangeComesBeforeCalibration(): void
    {
        $reminder = $this->remind(2, '2026-12-09 20:00', self::model(), self::forecast(-6.0, -6.0));

        self::assertNotNull($reminder);
        self::assertSame(ReminderReason::OutOfRange, $reminder->reason);
    }
}
