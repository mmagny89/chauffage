<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\TemperatureToneExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemperatureToneExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{float, string}>
     */
    public static function temperatures(): iterable
    {
        yield 'gel' => [-0.1, 'freezing'];
        yield 'zéro' => [0.0, 'cold'];
        yield 'froid' => [7.9, 'cold'];
        yield 'frais' => [8.0, 'cool'];
        yield 'doux' => [15.0, 'mild'];
        yield 'chaud' => [21.0, 'warm'];
    }

    #[DataProvider('temperatures')]
    public function testToneFollowsThresholds(float $celsius, string $tone): void
    {
        self::assertSame($tone, (new TemperatureToneExtension())->toneFor($celsius));
    }
}
