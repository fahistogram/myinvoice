<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Přečte archivované PDF přijaté faktury do paměti (pro parsery tankování).
 *
 * Resoluce archive rootu + path-traversal guard zrcadlí DownloadPurchaseInvoicePdfAction
 * (Windows case-insensitive realpath → strtolower obě strany). Vrací null, když faktura
 * nemá pdf_path nebo soubor na disku chybí.
 */
final class PurchaseInvoicePdfReader
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param array<string,mixed> $invoice  Výsledek PurchaseInvoiceRepository::find()
     */
    public function read(array $invoice): ?string
    {
        $relativePath = (string) ($invoice['pdf_path'] ?? '');
        if ($relativePath === '') return null;

        $archiveRootReal = realpath($this->resolveArchiveRoot());
        if ($archiveRootReal === false) return null;

        $fullPath = $archiveRootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $fullPathReal = realpath($fullPath);
        if ($fullPathReal === false || !is_file($fullPathReal)) return null;

        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $haystack = $isWindows ? strtolower($fullPathReal) : $fullPathReal;
        $needle   = ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($haystack, $needle)) return null;

        $bytes = @file_get_contents($fullPathReal);
        return $bytes === false ? null : $bytes;
    }

    private function resolveArchiveRoot(): string
    {
        $dir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($dir !== '') return $dir;
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') return dirname($uploads) . '/purchase-invoices';
        return RuntimePaths::storage('purchase-invoices');
    }
}
