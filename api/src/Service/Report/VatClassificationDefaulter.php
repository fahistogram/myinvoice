<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Auto-default VAT klasifikační kódy podle (direction, vat_rate, is_reverse_charge).
 *
 * **DB-driven** — defaultní mapování čte z `vat_classifications` table podle `vat_rate`.
 * Když se sazba změní (např. 21% → 20% k 1.1.2027), admin v Codebooks tab edituje
 * vat_classifications.vat_rate a defaulter automaticky chytne novou hodnotu.
 *
 * Pravidla per MF ČR (DPHDP3, aktuální seed):
 *   - Vystavená (sale, tuzemsko):    21% → 1,  12% → 2,  0% → 3
 *   - Vystavená (sale, reverse):     20 (EU dodání zboží)
 *   - Přijatá (purchase, tuzemsko):  21% → 40, 12% → 41, 0% → 42
 *   - Přijatá (purchase, reverse):   5  (tuzemský reverse charge)
 *
 * Algoritmus:
 *   1. Najdi v vat_classifications kód s direction matchne + vat_rate (tolerance 0.5%)
 *      + is_reverse_charge match + archived=0
 *   2. Vrátí code s nejmenším display_order (= "primární default" pro tu sazbu)
 *   3. Fallback hard-coded mapping pokud DB nemá kód (např. nový tenant before seed)
 */
final class VatClassificationDefaulter
{
    /** Hard-coded fallback (matchne seed v migrace 0037 pro CZ 2025-2026 sazby) */
    private const FALLBACK_SALE_TUZEMSKO    = ['21.0' => '1',  '12.0' => '2',  '0.0' => '3'];
    private const FALLBACK_PURCHASE_TUZEMSKO = ['21.0' => '40', '12.0' => '41', '0.0' => '42'];
    private const FALLBACK_SALE_REVERSE_EU       = '20';   // RC + EU odběratel = dodání do JČS → ř.20 (dod_zb)
    private const FALLBACK_SALE_REVERSE_DOMESTIC = '25s';  // RC + tuzemský odběratel = §92a dodavatel → ř.25 (pln_rez_pren), KH A.1
    private const FALLBACK_PURCHASE_REVERSE  = '5';

    /** @var array<string, string>|null In-memory cache code→cache key (per request) */
    private ?array $cache = null;

    public function __construct(private readonly Connection $db) {}

    /**
     * Default pro vystavenou fakturu (revenue side).
     *
     * `$taxDate` (volitelné) — když je zadán, dohledáme vat_rate platnou k tomu datu
     * (vat_rates.valid_from <= tax_date AND valid_to IS NULL OR >= tax_date). To řeší
     * scénář změny sazby od 1.1.2027 — faktury z 2026 chytnou starou klasifikaci,
     * faktury od 2027 novou (i když rate_percent se mění).
     *
     * `$supplierId` (volitelné, default 0 = jen globální) — multi-tenant scope.
     * Volej s tenant ID aby tenant viděl své custom kódy ELL.GLOBÁLNÍ; ne kódy jiných tenantů.
     */
    public function defaultForSale(float $vatRate, bool $reverseCharge = false, ?string $taxDate = null, int $supplierId = 0, bool $customerEuForeign = false): string
    {
        if ($reverseCharge) {
            // Tuzemský §92a dodavatel → '25s' (ř.25); dodání do JČS (zahraniční EU odběratel) → '20' (ř.20).
            return $this->lookup('sale', $vatRate, true, $taxDate, $supplierId)
                ?? ($customerEuForeign ? self::FALLBACK_SALE_REVERSE_EU : self::FALLBACK_SALE_REVERSE_DOMESTIC);
        }
        return $this->lookup('sale', $vatRate, false, $taxDate, $supplierId)
            ?? $this->byRateFallback($vatRate, self::FALLBACK_SALE_TUZEMSKO);
    }

    /**
     * Default pro přijatou fakturu (cost side).
     */
    public function defaultForPurchase(float $vatRate, bool $reverseCharge = false, ?string $taxDate = null, int $supplierId = 0): string
    {
        return $this->lookup('purchase', $vatRate, $reverseCharge, $taxDate, $supplierId)
            ?? ($reverseCharge ? self::FALLBACK_PURCHASE_REVERSE : $this->byRateFallback($vatRate, self::FALLBACK_PURCHASE_TUZEMSKO));
    }

    /**
     * DB lookup — najdi VAT klasifikační kód podle (direction, rate, reverse, taxDate).
     *
     * Algoritmus:
     *  1. Najdi v vat_rates ID sazby platné k taxDate s rate_percent matchnutou
     *     (vat_rates.valid_from <= taxDate AND (valid_to IS NULL OR >= taxDate)).
     *  2. V vat_classifications najdi kód s match (direction, vat_rate ≈, reverse).
     *  3. Pokud sazba není v `vat_rates` registrovaná, fallback na samotnou hodnotu.
     */
    private function lookup(string $direction, float $vatRate, bool $reverseCharge, ?string $taxDate = null, int $supplierId = 0): ?string
    {
        $key = "{$direction}:{$vatRate}:" . ($reverseCharge ? '1' : '0') . ':' . ($taxDate ?? '') . ':' . $supplierId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key] ?: null;
        }

        // Pokud je zadán taxDate, ověříme že sazba je v té době platná. Pokud admin
        // nastavil valid_to=2026-12-31 na CZ-21 a vytvořil CZ-20 s valid_from=2027,
        // pro fakturu z 2027 najdeme nový rate. Tady jen ověření existence;
        // mapování na vat_classifications stejně podle rate_percent.
        if ($taxDate !== null) {
            $rateCheck = $this->db->pdo()->prepare(
                "SELECT 1 FROM vat_rates
                  WHERE ABS(rate_percent - ?) < 0.5
                    AND valid_from <= ?
                    AND (valid_to IS NULL OR valid_to >= ?)
                  LIMIT 1"
            );
            $rateCheck->execute([$vatRate, $taxDate, $taxDate]);
            if ($rateCheck->fetchColumn() === false) {
                // Rate v té době neexistuje (možná uživatel uloží fakturu s sazbou
                // 21% mimo platný rozsah) — pokračujeme do lookup stejně, ale
                // logujeme by mohlo dávat smysl. Pro teď silent fallback.
            }
        }

        // **Multi-tenant scope** — vrátí globální seed (supplier_id IS NULL) NEBO
        // tenant-specific kódy. NIKDY kódy jiných tenantů. Pokud má tenant custom
        // kód se stejnou sazbou jako globální, preferujeme tenant-specific
        // (supplier_id NOT NULL = 1, ORDER DESC).
        $stmt = $this->db->pdo()->prepare(
            "SELECT code FROM vat_classifications
              WHERE archived = 0
                AND (direction = ? OR direction = 'both')
                AND ABS(COALESCE(vat_rate, -999) - ?) < 0.5
                AND is_reverse_charge = ?
                AND (supplier_id IS NULL OR supplier_id = ?)
           ORDER BY (supplier_id IS NOT NULL) DESC, display_order ASC
              LIMIT 1"
        );
        $stmt->execute([$direction, $vatRate, $reverseCharge ? 1 : 0, $supplierId]);
        $code = $stmt->fetchColumn();

        $result = $code !== false ? (string) $code : null;
        $this->cache[$key] = $result ?? '';
        return $result;
    }

    private function byRateFallback(float $vatRate, array $map): string
    {
        foreach ($map as $rateStr => $code) {
            if (abs($vatRate - (float) $rateStr) < 0.5) return $code;
        }
        return $map['0.0'] ?? '3';
    }

    /**
     * Aplikuje default na header faktury (pokud chybí).
     * Většinou se aplikuje při uložení (CreateAction / UpdateAction).
     *
     * Pro header zvolíme dominantní sazbu z items (max(total) za sazbu).
     *
     * @param list<array{vat_rate?:float, total_with_vat?:float}> $items
     */
    public function suggestHeaderForInvoice(array $items, bool $reverseCharge, string $direction, ?string $taxDate = null, int $supplierId = 0, bool $customerEuForeign = false): string
    {
        // Najdi dominantní sazbu (s největší totální částkou)
        $byRate = [];
        foreach ($items as $it) {
            $rate = (float) ($it['vat_rate'] ?? 0);
            $total = abs((float) ($it['total_with_vat'] ?? 0));
            if (!isset($byRate[(string) $rate])) $byRate[(string) $rate] = 0.0;
            $byRate[(string) $rate] += $total;
        }
        $dominantRate = 21.0;
        if (!empty($byRate)) {
            arsort($byRate);
            $dominantRate = (float) array_key_first($byRate);
        }
        return $direction === 'sale'
            ? $this->defaultForSale($dominantRate, $reverseCharge, $taxDate, $supplierId, $customerEuForeign)
            : $this->defaultForPurchase($dominantRate, $reverseCharge, $taxDate, $supplierId);
    }
}
