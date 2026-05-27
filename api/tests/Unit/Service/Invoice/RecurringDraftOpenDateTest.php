<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * draftOpenDate() = první den měsíce, ve kterém leží next_run_date. To je den,
 * kdy se pro draft_open_mode='period_start' otevírá koncept (začátek období).
 */
final class RecurringDraftOpenDateTest extends TestCase
{
    public function testEndOfMonthIssueOpensFirstOfMonth(): void
    {
        // Vystavení 30.6. → koncept se otevírá 1.6.
        self::assertSame('2026-06-01', RecurringInvoiceGenerator::draftOpenDate('2026-06-30'));
    }

    public function testMidMonthIssueOpensFirstOfMonth(): void
    {
        // Vystavení 20.6. → koncept se stále otevírá 1.6.
        self::assertSame('2026-06-01', RecurringInvoiceGenerator::draftOpenDate('2026-06-20'));
    }

    public function testFirstOfMonthIssueOpensSameDay(): void
    {
        self::assertSame('2026-06-01', RecurringInvoiceGenerator::draftOpenDate('2026-06-01'));
    }

    public function testFebruaryLeapYear(): void
    {
        self::assertSame('2024-02-01', RecurringInvoiceGenerator::draftOpenDate('2024-02-29'));
    }
}
