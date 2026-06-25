<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\Fuel\FuelStatementParser;
use MyInvoice\Service\Logbook\Fuel\FuelStatementParserRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Registry vybírá první parser, který fakturu podporuje a vrátí ne-prázdný výsledek.
 * Ověřuje rozšiřitelnost: přidání nového parseru na začátek změní výběr bez zásahu
 * do scanneru.
 */
final class FuelStatementParserRegistryTest extends TestCase
{
    private function parser(string $name, bool $supports, ?array $transactions): FuelStatementParser
    {
        return new class($name, $supports, $transactions) implements FuelStatementParser {
            public function __construct(private string $n, private bool $s, private ?array $tx) {}
            public function name(): string { return $this->n; }
            public function supports(array $invoice): bool { return $this->s; }
            public function parse(array $invoice, ?string $pdfBytes): ?array
            {
                if ($this->tx === null) return null;
                return ['transactions' => $this->tx, 'status' => 'parsed'];
            }
        };
    }

    private function row(): array
    {
        return ['fueled_date' => '2026-01-01', 'amount_with_vat' => 100.0, 'is_fuel' => true];
    }

    public function testPicksFirstSupportingParserThatSucceeds(): void
    {
        $registry = new FuelStatementParserRegistry([
            $this->parser('axigon', false, [$this->row()]),   // nepodporuje → skip
            $this->parser('ai', true, null),                  // podporuje, ale vrátí null → fall through
            $this->parser('summary', true, [$this->row()]),   // podporuje a uspěje
        ]);
        $result = $registry->parse(['supplier_id' => 1], null);
        self::assertSame('summary', $result['parser']);
        self::assertCount(1, $result['transactions']);
        self::assertSame('parsed', $result['status']);
    }

    public function testNewParserAtFrontTakesPrecedence(): void
    {
        $registry = new FuelStatementParserRegistry([
            $this->parser('new_vendor', true, [$this->row(), $this->row()]),
            $this->parser('summary', true, [$this->row()]),
        ]);
        $result = $registry->parse(['supplier_id' => 1], null);
        self::assertSame('new_vendor', $result['parser']);
        self::assertCount(2, $result['transactions']);
    }

    public function testNoParserMatches(): void
    {
        $registry = new FuelStatementParserRegistry([
            $this->parser('a', false, [$this->row()]),
            $this->parser('b', true, null),
        ]);
        $result = $registry->parse([], null);
        self::assertSame('none', $result['parser']);
        self::assertSame('failed', $result['status']);
        self::assertSame([], $result['transactions']);
    }
}
