<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\LogAnalysis;

/**
 * Konektor pro konkrétní formát logu poštovního serveru.
 *
 * Cílem je převést syrový log (každý server má vlastní formát) na jednotný
 * proud {@see SmtpLogEvent} záznamů, se kterými už pracuje univerzální
 * {@see SmtpLogAnalyzer} (agregace, filtry, UI). Přidání podpory dalšího
 * serveru (Postfix, Exim, MailEnable…) = nová třída implementující toto
 * rozhraní + zapsání do {@see SmtpLogAnalyzer::CONNECTORS}.
 */
interface SmtpLogConnectorInterface
{
    /**
     * Strojový identifikátor konektoru (klíč v cfg `smtp_log.connector`).
     */
    public function key(): string;

    /**
     * Lidsky čitelný název serveru (do UI).
     */
    public function label(): string;

    /**
     * Rozhodne, zda daný soubor patří tomuto konektoru (dle názvu/obsahu).
     * Analyzer díky tomu umí přeskočit cizí soubory v zadaném glob vzoru.
     */
    public function matchesFile(string $path, string $firstChunk): bool;

    /**
     * Rozparsuje obsah jednoho log souboru na jednotné události.
     *
     * @param string $contents Celý obsah log souboru (UTF-8 / ASCII).
     * @param string $sourceFile Název souboru (jen pro referenci v události).
     * @return list<SmtpLogEvent>
     */
    public function parse(string $contents, string $sourceFile): array;
}
