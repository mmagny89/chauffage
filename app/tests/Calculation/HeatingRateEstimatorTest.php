<?php

declare(strict_types=1);

namespace App\Tests\Calculation;

use App\Calculation\HeatingRate;
use App\Calculation\HeatingRateEstimator;
use App\Entity\HeatingStart;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class HeatingRateEstimatorTest extends TestCase
{
    private Household $household;

    protected function setUp(): void
    {
        $this->household = new Household(new User());
    }

    private function start(string $place, float $indoor, float $setpoint, ?int $minutes, string $at = '2026-12-01 07:00'): HeatingStart
    {
        $start = new HeatingStart(new Place($this->household, $place), new \DateTimeImmutable($at), $setpoint, $indoor, 5.0);
        if (null !== $minutes) {
            $start->markReached((new \DateTimeImmutable($at))->modify(\sprintf('+%d minutes', $minutes)));
        }

        return $start;
    }

    public function testNoRateBeforeEnoughCompletedStarts(): void
    {
        $rates = (new HeatingRateEstimator())->estimate([$this->start('Salon', 16.0, 20.0, 120), $this->start('Salon', 16.0, 20.0, null, '2026-12-02 07:00')]);

        self::assertNull($rates->household);
        self::assertSame([], $rates->byPlace);
        self::assertSame(1, $rates->completed);
        self::assertNull($rates->forPlace('Salon'));
    }

    public function testRateIsTheMeanOfDegreesPerHour(): void
    {
        // +4 °C en 2 h = 2 °C/h ; +3 °C en 3 h = 1 °C/h : moyenne 1,5.
        $rates = (new HeatingRateEstimator())->estimate([$this->start('Salon', 16.0, 20.0, 120), $this->start('Salon', 17.0, 20.0, 180, '2026-12-02 07:00')]);

        self::assertNotNull($rates->household);
        self::assertSame(1.5, $rates->household->degreesPerHour);
        self::assertSame(2, $rates->household->samples);
        self::assertSame(1.5, $rates->forPlace('Salon')?->degreesPerHour);
    }

    public function testAPlaceWithTooFewSamplesFallsBackToTheHouseholdRate(): void
    {
        $rates = (new HeatingRateEstimator())->estimate([
            $this->start('Salon', 16.0, 20.0, 120),
            $this->start('Salon', 16.0, 20.0, 120, '2026-12-02 07:00'),
            $this->start('Cave', 10.0, 20.0, 600, '2026-12-03 07:00'),
        ]);

        self::assertSame(2.0, $rates->forPlace('Salon')?->degreesPerHour);
        self::assertSame(1.67, $rates->forPlace('Cave')?->degreesPerHour, 'Un seul allumage à la cave : taux du foyer (2, 2 et 1 °C/h).');
        self::assertSame(3, $rates->completed);
    }

    public function testTooShortOrTooSmallRisesAreIgnored(): void
    {
        $rates = (new HeatingRateEstimator())->estimate([
            $this->start('Salon', 16.0, 20.0, 5),
            $this->start('Salon', 19.8, 20.0, 60, '2026-12-02 07:00'),
            $this->start('Salon', 16.0, 20.0, 120, '2026-12-03 07:00'),
        ]);

        self::assertNull($rates->household, 'Un seul allumage exploitable.');
        self::assertSame(3, $rates->completed);
    }

    public function testMinutesToRiseAreRoundedUpToFiveMinutes(): void
    {
        $rate = new HeatingRate(2.0, 3);

        self::assertSame(60, $rate->minutesToRise(2.0));
        self::assertSame(35, $rate->minutesToRise(1.0 + 0.1), '33 min arrondies à 35.');
        self::assertSame(5, $rate->minutesToRise(0.01));
    }

    public function testNotReachedBeforeTheStartOrAfter24Hours(): void
    {
        $start = $this->start('Salon', 16.0, 20.0, null);

        $this->expectException(\InvalidArgumentException::class);
        $start->markReached(new \DateTimeImmutable('2026-11-30 07:00'));
    }

    public function testTooLongAfterTheStartIsRefused(): void
    {
        $start = $this->start('Salon', 16.0, 20.0, null);

        $this->expectException(\InvalidArgumentException::class);
        $start->markReached(new \DateTimeImmutable('2026-12-02 07:01'));
    }
}
