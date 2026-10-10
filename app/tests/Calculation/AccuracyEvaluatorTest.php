<?php

declare(strict_types=1);

namespace App\Tests\Calculation;

use App\Calculation\AccuracyEvaluator;
use App\Calculation\DeltaModelFitter;
use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use App\Enum\DaySlot;
use PHPUnit\Framework\TestCase;

final class AccuracyEvaluatorTest extends TestCase
{
    private Place $salon;

    protected function setUp(): void
    {
        $this->salon = new Place(new Household(new User()), 'Salon');
    }

    private function reading(string $at, float $outdoor, float $indoor, ?Place $place = null): Reading
    {
        return new Reading($place ?? $this->salon, new \DateTimeImmutable($at), $outdoor, $indoor);
    }

    private function evaluate(Reading ...$readings): \App\Calculation\AccuracyReport
    {
        return (new AccuracyEvaluator(new DeltaModelFitter()))->evaluate($readings);
    }

    public function testNothingToCompareWithASingleSession(): void
    {
        $report = $this->evaluate($this->reading('2026-12-01 08:00', 5.0, 18.0));

        self::assertTrue($report->isEmpty());
        self::assertSame(1, $report->sessions);
        self::assertSame(1, $report->skipped);
    }

    public function testPerfectlyLinearHouseHasNoErrorNearTheTrainingPoints(): void
    {
        // Même température extérieure à chaque séance : la pente typique n'intervient pas, l'écart est constant (+13).
        $report = $this->evaluate(
            $this->reading('2026-12-01 08:00', 5.0, 18.0),
            $this->reading('2026-12-02 08:00', 5.0, 18.0),
            $this->reading('2026-12-03 08:00', 5.0, 18.0),
        );

        self::assertNotNull($report->overall);
        self::assertSame(3, $report->overall->count);
        self::assertSame(0.0, $report->overall->meanAbsoluteError);
        self::assertSame(0.0, $report->overall->bias);
        self::assertSame(1.0, $report->overall->withinTolerance);
        self::assertFalse($report->overall->isBiased());
    }

    public function testTheHeldOutSessionIsNotUsedToEstimateItself(): void
    {
        // Deux séances : chacune est estimée avec l'autre seule, jamais avec elle-même.
        // Séance A : 5 °C dehors, 18 °C dedans (écart +13). Séance B : 5 °C dehors, 20 °C dedans (+15).
        $report = $this->evaluate(
            $this->reading('2026-12-01 08:00', 5.0, 18.0),
            $this->reading('2026-12-02 08:00', 5.0, 20.0),
        );

        self::assertNotNull($report->overall);
        // A estimée à 5 + 15 = 20 (erreur +2,0) ; B estimée à 5 + 13 = 18 (erreur -2,0).
        self::assertSame(2.0, $report->overall->meanAbsoluteError);
        self::assertSame(0.0, $report->overall->bias);
        self::assertSame(0.0, $report->overall->withinTolerance);
    }

    public function testBiasIsSignedAndFlagged(): void
    {
        // Relevés du matin à écart +13 ; le soir (autre créneau) à écart +5. Le matin est estimé avec
        // le modèle général quand le créneau n'a plus de relevé : ici chaque créneau en garde un.
        $report = $this->evaluate(
            $this->reading('2026-12-01 08:00', 5.0, 18.0),
            $this->reading('2026-12-02 08:00', 5.0, 18.0),
            $this->reading('2026-12-03 08:00', 5.0, 18.0),
            $this->reading('2026-12-04 20:00', 5.0, 10.0),
        );

        // Le seul relevé du soir est estimé avec le modèle général (3 relevés à +13) : 18 au lieu de 10, soit +8.
        $evening = $report->bySlot[DaySlot::Evening->value];
        self::assertSame(1, $evening->count);
        self::assertSame(8.0, $evening->bias);
        self::assertTrue($evening->isBiased());
        self::assertSame(0.0, $report->bySlot[DaySlot::Morning->value]->meanAbsoluteError);
    }

    public function testSessionsGroupEveryPlaceMeasuredAtTheSameInstant(): void
    {
        $cave = new Place($this->salon->getHousehold(), 'Cave');
        $report = $this->evaluate(
            $this->reading('2026-12-01 08:00', 5.0, 18.0),
            $this->reading('2026-12-01 08:00', 5.0, 12.0, $cave),
            $this->reading('2026-12-02 08:00', 5.0, 18.0),
            $this->reading('2026-12-02 08:00', 5.0, 12.0, $cave),
        );

        self::assertSame(2, $report->sessions);
        self::assertSame(['Cave', 'Salon'], array_keys($report->byPlace));
        // Le modèle du foyer (écart moyen +10) se trompe de 3 °C sur chaque pièce, en sens contraires.
        self::assertSame(3.0, $report->byPlace['Salon']->meanAbsoluteError);
        self::assertSame(-3.0, $report->byPlace['Salon']->bias);
        self::assertSame(3.0, $report->byPlace['Cave']->bias);
    }

    public function testResultIsProvisionalUntilEnoughSessions(): void
    {
        $readings = [];
        for ($i = 1; $i < AccuracyEvaluator::RELIABLE_FROM_SESSIONS; ++$i) {
            $readings[] = $this->reading(\sprintf('2026-12-%02d 08:00', $i), 5.0, 18.0);
        }
        self::assertTrue($this->evaluate(...$readings)->isProvisional());

        $readings[] = $this->reading('2026-12-20 08:00', 5.0, 18.0);
        self::assertFalse($this->evaluate(...$readings)->isProvisional());
    }
}
