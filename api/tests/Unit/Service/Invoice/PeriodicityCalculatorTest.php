<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\PeriodicityCalculator;
use PHPUnit\Framework\TestCase;

final class PeriodicityCalculatorTest extends TestCase
{
    public function testMonthlyPlainDay(): void
    {
        // 15. ledna 2026 → 15. února
        self::assertSame(
            '2026-02-15',
            PeriodicityCalculator::nextRunDate('2026-01-15', 'monthly', false, 15),
        );
    }

    public function testMonthlyDayFromCurrent(): void
    {
        // day_of_month=null → bere se z $current
        self::assertSame(
            '2026-02-15',
            PeriodicityCalculator::nextRunDate('2026-01-15', 'monthly', false, null),
        );
    }

    public function testMonthlyEndOfMonthAcrossDifferentLengths(): void
    {
        // EOM přes leden(31) → únor(28) → březen(31) → duben(30)
        $cur = '2026-01-31';
        $cur = PeriodicityCalculator::nextRunDate($cur, 'monthly', true, null);
        self::assertSame('2026-02-28', $cur);
        $cur = PeriodicityCalculator::nextRunDate($cur, 'monthly', true, null);
        self::assertSame('2026-03-31', $cur);
        $cur = PeriodicityCalculator::nextRunDate($cur, 'monthly', true, null);
        self::assertSame('2026-04-30', $cur);
    }

    public function testMonthlyEndOfMonthLeapYear(): void
    {
        // 2024 je přestupný — únor má 29 dní
        self::assertSame(
            '2024-02-29',
            PeriodicityCalculator::nextRunDate('2024-01-31', 'monthly', true, null),
        );
        self::assertSame(
            '2025-02-28',
            PeriodicityCalculator::nextRunDate('2025-01-31', 'monthly', true, null),
        );
    }

    public function testQuarterlyEndOfMonth(): void
    {
        // 31.3 → 30.6 → 30.9 → 31.12
        $cur = '2026-03-31';
        $cur = PeriodicityCalculator::nextRunDate($cur, 'quarterly', true, null);
        self::assertSame('2026-06-30', $cur);
        $cur = PeriodicityCalculator::nextRunDate($cur, 'quarterly', true, null);
        self::assertSame('2026-09-30', $cur);
        $cur = PeriodicityCalculator::nextRunDate($cur, 'quarterly', true, null);
        self::assertSame('2026-12-31', $cur);
    }

    public function testSemiAnnually(): void
    {
        self::assertSame(
            '2026-09-15',
            PeriodicityCalculator::nextRunDate('2026-03-15', 'semi_annually', false, 15),
        );
    }

    public function testAnnuallyEndOfMonth(): void
    {
        // Každoroční „31. prosince"
        self::assertSame(
            '2027-12-31',
            PeriodicityCalculator::nextRunDate('2026-12-31', 'annually', true, null),
        );
    }

    public function testDayCappedAt28(): void
    {
        // Bezpečnostní cap pro případ, kdyby validace propustila > 28
        self::assertSame(
            '2026-02-28',
            PeriodicityCalculator::nextRunDate('2026-01-15', 'monthly', false, 31),
        );
    }

    public function testMonthOverflowYearChange(): void
    {
        self::assertSame(
            '2027-01-15',
            PeriodicityCalculator::nextRunDate('2026-12-15', 'monthly', false, null),
        );
    }

    public function testNthRunDateSequence(): void
    {
        // 0. cyklus = anchor; n. cyklus = anchor + n*period
        self::assertSame(
            '2026-01-15',
            PeriodicityCalculator::nthRunDate('2026-01-15', 'monthly', false, null, 0),
        );
        self::assertSame(
            '2026-04-15',
            PeriodicityCalculator::nthRunDate('2026-01-15', 'monthly', false, null, 3),
        );
        self::assertSame(
            '2027-01-15',
            PeriodicityCalculator::nthRunDate('2026-01-15', 'monthly', false, null, 12),
        );
    }

    public function testMonthsForMatchesFrequencyEnum(): void
    {
        self::assertSame(1,  PeriodicityCalculator::monthsFor('monthly'));
        self::assertSame(3,  PeriodicityCalculator::monthsFor('quarterly'));
        self::assertSame(6,  PeriodicityCalculator::monthsFor('semi_annually'));
        self::assertSame(12, PeriodicityCalculator::monthsFor('annually'));
    }

    public function testUnknownFrequencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PeriodicityCalculator::monthsFor('weekly');
    }

    // --- snapToDayRule: přemapování dne ve VLASTNÍM měsíci (bez posunu cyklu) ---

    public function testSnapToEndOfMonthKeepsMonth(): void
    {
        // Změna „20." → „konec měsíce" se projeví hned na nejbližším vystavení (červen).
        self::assertSame(
            '2026-06-30',
            PeriodicityCalculator::snapToDayRule('2026-06-20', true, null),
        );
    }

    public function testSnapToDayOfMonthKeepsMonth(): void
    {
        // Změna „konec měsíce" → „10." přemapuje 30.6. na 10.6.
        self::assertSame(
            '2026-06-10',
            PeriodicityCalculator::snapToDayRule('2026-06-30', false, 10),
        );
    }

    public function testSnapSameRuleIsIdempotent(): void
    {
        // Re-aplikace stejného pravidla na stejné datum nic nemění.
        self::assertSame(
            '2026-06-20',
            PeriodicityCalculator::snapToDayRule('2026-06-20', false, 20),
        );
        self::assertSame(
            '2026-02-28',
            PeriodicityCalculator::snapToDayRule('2026-02-28', true, null),
        );
    }

    public function testSnapDayCappedAt28(): void
    {
        self::assertSame(
            '2026-02-28',
            PeriodicityCalculator::snapToDayRule('2026-02-15', false, 31),
        );
    }

    public function testSnapNullDayUsesExistingDay(): void
    {
        self::assertSame(
            '2026-06-20',
            PeriodicityCalculator::snapToDayRule('2026-06-20', false, null),
        );
    }
}
