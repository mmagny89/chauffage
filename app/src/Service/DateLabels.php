<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Libellés de dates en français pour l'interface, dans le fuseau du foyer.
 */
final class DateLabels
{
    /** @var array<string, \IntlDateFormatter> */
    private array $formatters = [];

    /**
     * Jour court : « ven. 9 oct. ».
     */
    public function shortDay(\DateTimeInterface $date, string $timezone): string
    {
        return $this->format($date, $timezone, 'EEE d MMM');
    }

    /**
     * Jour complet : « jeudi 8 octobre 2026 ».
     */
    public function fullDay(\DateTimeInterface $date, string $timezone): string
    {
        return $this->format($date, $timezone, 'EEEE d MMMM y');
    }

    private function format(\DateTimeInterface $date, string $timezone, string $pattern): string
    {
        $formatter = $this->formatters[$timezone.'|'.$pattern] ??= new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $timezone, null, $pattern);

        return (string) $formatter->format($date);
    }
}
