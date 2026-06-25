<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\Fuel;

/**
 * Registry parserů detailních výpisů tankování. Drží seřazený seznam strategií;
 * pick/parse vrátí výsledek prvního parseru, který fakturu podporuje a uspěje.
 *
 * Pořadí (z Bootstrap DI): Axigon (interní) → AI fallback → Summary (vždy uspěje).
 * Přidání nové tankovací společnosti = nová třída do tohoto seznamu v Bootstrap.
 */
final class FuelStatementParserRegistry
{
    /** @var list<FuelStatementParser> */
    private array $parsers;

    /** @param list<FuelStatementParser> $parsers */
    public function __construct(array $parsers)
    {
        $this->parsers = array_values($parsers);
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array{transactions: list<array<string,mixed>>, status: string, parser: string}
     */
    public function parse(array $invoice, ?string $pdfBytes): array
    {
        foreach ($this->parsers as $parser) {
            if (!$parser->supports($invoice)) continue;
            $result = $parser->parse($invoice, $pdfBytes);
            if ($result !== null && ($result['transactions'] ?? []) !== []) {
                return [
                    'transactions' => array_values($result['transactions']),
                    'status'       => (string) ($result['status'] ?? 'parsed'),
                    'parser'       => $parser->name(),
                ];
            }
        }
        return ['transactions' => [], 'status' => 'failed', 'parser' => 'none'];
    }
}
