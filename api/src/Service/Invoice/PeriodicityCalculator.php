<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Počítá next_run_date pro pravidelnou fakturu.
 *
 * Periodicity:
 *   monthly         → +1 měsíc
 *   quarterly       → +3 měsíce
 *   semi_annually   → +6 měsíců
 *   annually        → +12 měsíců
 *
 * Pravidlo dne v měsíci:
 *   end_of_month=1            → poslední den cílového měsíce (28/29/30/31 dynamicky)
 *   day_of_month=1..28        → konkrétní den (28 max kvůli únoru)
 *   day_of_month=NULL         → day se odvodí z aktuálního next_run_date
 *
 * Implementace přes DateTimeImmutable. Vyhýbáme se MariaDB DATE_ADD edge case,
 * kde "2026-01-31 + 1 month" = "2026-02-28" — to PHP DateTime také dělá, ale
 * pro nás je to čistší přes "first day of this month" + N měsíců + nastavení dne.
 */
final class PeriodicityCalculator
{
    public const FREQUENCIES = ['monthly', 'quarterly', 'semi_annually', 'annually'];

    public static function monthsFor(string $frequency): int
    {
        return match ($frequency) {
            'monthly'       => 1,
            'quarterly'     => 3,
            'semi_annually' => 6,
            'annually'      => 12,
            default => throw new \InvalidArgumentException("Unknown frequency: $frequency"),
        };
    }

    /**
     * Vrátí YYYY-MM-DD příští faktury, počítáno z $current data + $frequency posunu.
     *
     * @param string $current        YYYY-MM-DD — aktuální next_run_date nebo anchor
     * @param string $frequency      monthly | quarterly | semi_annually | annually
     * @param bool   $endOfMonth     true = poslední den měsíce (přebije day_of_month)
     * @param int|null $dayOfMonth   1-28 nebo NULL (= z $current)
     */
    public static function nextRunDate(
        string $current,
        string $frequency,
        bool $endOfMonth,
        ?int $dayOfMonth,
    ): string {
        $months = self::monthsFor($frequency);
        $base = new \DateTimeImmutable($current);

        // "first day of this month" + N měsíců → vyhne se přetékání ("2026-01-31 + 1 month" → "2026-03-03")
        $target = $base
            ->modify('first day of this month')
            ->modify("+{$months} months");

        if ($endOfMonth) {
            return $target->modify('last day of this month')->format('Y-m-d');
        }

        $day = $dayOfMonth ?? (int) $base->format('j');
        $day = max(1, min(28, $day));

        return $target
            ->setDate((int) $target->format('Y'), (int) $target->format('n'), $day)
            ->format('Y-m-d');
    }

    /**
     * Přemapuje DEN data dle pravidla (end_of_month / day_of_month) v rámci JEHO
     * VLASTNÍHO měsíce — bez posunu cyklu. Použití: uživatel u běžící šablony změní
     * den vystavení nebo přepne na „konec měsíce", a očekává, že se nejbližší
     * naplánované vystavení (next_run_date) přemapuje na nový den téhož měsíce.
     */
    public static function snapToDayRule(string $date, bool $endOfMonth, ?int $dayOfMonth): string
    {
        $base = new \DateTimeImmutable($date);
        if ($endOfMonth) {
            return $base->modify('last day of this month')->format('Y-m-d');
        }
        $day = $dayOfMonth ?? (int) $base->format('j');
        $day = max(1, min(28, $day));
        return $base
            ->setDate((int) $base->format('Y'), (int) $base->format('n'), $day)
            ->format('Y-m-d');
    }

    /**
     * Vrátí konkrétní `issue_date` pro vygenerovanou fakturu z anchor + N cyklů.
     * Používá se např. když potřebujeme spočítat datum N-té faktury bez procházení DB.
     */
    public static function nthRunDate(
        string $anchor,
        string $frequency,
        bool $endOfMonth,
        ?int $dayOfMonth,
        int $n,
    ): string {
        if ($n < 0) throw new \InvalidArgumentException('n must be >= 0');
        $months = self::monthsFor($frequency) * $n;
        $base = new \DateTimeImmutable($anchor);

        $target = $base
            ->modify('first day of this month')
            ->modify(($months >= 0 ? '+' : '') . "{$months} months");

        if ($endOfMonth) {
            return $target->modify('last day of this month')->format('Y-m-d');
        }

        $day = $dayOfMonth ?? (int) $base->format('j');
        $day = max(1, min(28, $day));

        return $target
            ->setDate((int) $target->format('Y'), (int) $target->format('n'), $day)
            ->format('Y-m-d');
    }
}
