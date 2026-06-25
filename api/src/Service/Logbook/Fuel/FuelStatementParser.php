<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\Fuel;

/**
 * Strategie pro vytěžení jednotlivých tankování z přijaté faktury benzínky.
 *
 * Přidání nové tankovací společnosti = nová třída implementující toto rozhraní
 * + zaregistrování v {@see FuelStatementParserRegistry} (DI v Bootstrap). Scanner
 * ani akce se nemění.
 *
 * Registry volá parsery v pořadí; první, jehož supports() === true a parse()
 * vrátí ne-null výsledek, vyhrává.
 *
 * Tvar jedné transakce (klíče):
 *   fueled_date (Y-m-d), fueled_time (H:i|null), fuel_type (string|null),
 *   quantity (float|null), unit (string), unit_price (float|null),
 *   amount_without_vat (float|null), amount_vat (float|null), amount_with_vat (float),
 *   currency (CHAR3), station (string|null), receipt_number (string|null),
 *   is_fuel (bool), raw_text (string|null)
 */
interface FuelStatementParser
{
    /** Stabilní identifikátor parseru (uloží se do logbook_fuel_scans.parser). */
    public function name(): string;

    /**
     * Umí tento parser zpracovat fakturu daného dodavatele?
     *
     * @param array<string,mixed> $invoice  Výsledek PurchaseInvoiceRepository::find()
     */
    public function supports(array $invoice): bool;

    /**
     * Vytěží transakce z faktury. Vrací null = „neumím / nevyšlo" (registry zkusí další).
     *
     * @param array<string,mixed> $invoice
     * @param string|null         $pdfBytes  Obsah archivovaného PDF (může být null).
     * @return array{transactions: list<array<string,mixed>>, status: string}|null
     */
    public function parse(array $invoice, ?string $pdfBytes): ?array;
}
