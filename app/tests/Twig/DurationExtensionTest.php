<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\DurationExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function durations(): iterable
    {
        yield 'minutes' => [45, '45 min'];
        yield 'heure pleine' => [120, '2 h'];
        yield 'heures et minutes' => [90, '1 h 30'];
        yield 'minutes sur deux chiffres' => [65, '1 h 05'];
        yield 'zéro' => [0, '0 min'];
    }

    #[DataProvider('durations')]
    public function testFormat(int $minutes, string $expected): void
    {
        self::assertSame($expected, (new DurationExtension())->duration($minutes));
    }
}
