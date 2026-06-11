-- 0072: Tuzemský režim přenesení daňové povinnosti — DODAVATEL (§ 92a–92e, např. stavební práce).
--
-- Číselník dosud neměl SALE kód pro tuzemského RC dodavatele (DPHDP3 ř.25 `pln_rez_pren`).
-- Vystavené RC se proto klasifikovalo kódem '20' (Dodání zboží do JČS) — v KH to díky
-- is_reverse_charge flagu padlo do A.1 (správně), ale v DPHDP3 na ř.20 (dod_zb) místo ř.25.
--
-- Nový kód '25s': dphdp3_line='25' (pln_rez_pren), kh_section='A.1', is_reverse_charge=1,
-- vat_rate NULL (sazbově agnostický — RC dodavatel daň neúčtuje; ř.25 nerozlišuje sazbu).
-- Country-aware klasifikace (VatLedgerService / VatClassificationDefaulter) přiřadí '25s'
-- tuzemskému odběrateli, kód '20' nechá pro skutečné dodání do JČS (zahraniční EU odběratel).
--
-- Idempotentní: UNIQUE(supplier_id, code) s NULL supplier_id NEdeduplikuje (NULL != NULL),
-- proto INSERT … SELECT … WHERE NOT EXISTS, ne INSERT IGNORE.

INSERT INTO vat_classifications
    (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, display_order)
SELECT NULL, '25s', 'Tuzemský režim přenesení daňové povinnosti – dodavatel (§ 92a–92e)',
       'sale', '25', 'A.1', NULL, 1, 23
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM vat_classifications WHERE code = '25s' AND supplier_id IS NULL
);
