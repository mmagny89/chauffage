<?php

declare(strict_types=1);

namespace App\Tests\Calculation;

use App\Calculation\AccuracyEvaluator;
use App\Calculation\DeltaModelFitter;
use App\Calculation\SlopeTuner;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class SlopeTunerTest extends TestCase
{
    private SlopeTuner $tuner;
    private Place $salon;

    protected function setUp(): void
    {
        $this->tuner = new SlopeTuner(new AccuracyEvaluator(new DeltaModelFitter()));
        $this->salon = new Place(new Household(new User()), 'Salon');
    }

    /**
     * @param list<float> $outdoors une séance par température extérieure
     *
     * @return list<Reading>
     */
    private function readings(array $outdoors, float $slope): array
    {
        $readings = [];
        foreach ($outdoors as $i => $outdoor) {
            // Écart réel = 20 + pente × extérieur.
            $indoor = $outdoor + 20 + $slope * $outdoor;
            $readings[] = new Reading($this->salon, new \DateTimeImmutable(\sprintf('2026-12-%02d 08:00', $i + 1)), $outdoor, round($indoor, 1));
        }

        return $readings;
    }

    public function testTooFewSessionsProposeNothing(): void
    {
        $tuning = $this->tuner->tune($this->readings([2.0, 4.0, 6.0], -0.8), DeltaModelFitter::TYPICAL_SLOPE);

        self::assertFalse($tuning->enoughData);
        self::assertFalse($tuning->isWorthApplying());
        self::assertSame(DeltaModelFitter::TYPICAL_SLOPE, $tuning->best);
    }

    public function testAHouseThatFollowsTheTypicalSlopeIsLeftAlone(): void
    {
        $tuning = $this->tuner->tune($this->readings([2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0], DeltaModelFitter::TYPICAL_SLOPE), DeltaModelFitter::TYPICAL_SLOPE);

        self::assertTrue($tuning->enoughData);
        self::assertFalse($tuning->isWorthApplying());
        self::assertSame(DeltaModelFitter::TYPICAL_SLOPE, $tuning->best);
    }

    public function testAHouseMuchMoreCoupledToTheOutsideGetsASteeperSlope(): void
    {
        // Écart réel qui chute de 0,8 °C par degré, relevés peu variés (2 à 9 °C) : la pente typique tire trop.
        $tuning = $this->tuner->tune($this->readings([2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0], -0.8), DeltaModelFitter::TYPICAL_SLOPE);

        self::assertTrue($tuning->isWorthApplying());
        self::assertLessThanOrEqual(-0.7, $tuning->best);
        self::assertNotNull($tuning->currentError);
        self::assertNotNull($tuning->bestError);
        self::assertGreaterThanOrEqual(SlopeTuner::MIN_GAIN, round($tuning->currentError - $tuning->bestError, 1));
    }

    public function testStartingFromTheBestSlopeNothingBetterIsProposed(): void
    {
        $readings = $this->readings([2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0], -0.8);
        $best = $this->tuner->tune($readings, DeltaModelFitter::TYPICAL_SLOPE)->best;

        self::assertFalse($this->tuner->tune($readings, $best)->isWorthApplying());
    }
}
