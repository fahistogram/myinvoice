-- MyInvoice.cz — Třístranný obchod (§ 17 ZDPH) — klasifikační kódy
--
-- Daňový audit (2026-05): souhrnné hlášení (SHV) mělo chybné mapování kódu plnění
-- (služby hlášené jako k_pln_eu=2 = třístranný obchod místo 3). Oprava mapování je
-- v SouhrnneHlaseniBuilder; tato migrace doplňuje chybějící klasifikační kódy, aby
-- prostřední osoba třístranného obchodu mohla plnění vykázat:
--
--   • '30' (přijaté) — Pořízení zboží prostřední osobou → DPHDP3 ř. 30 (tri_pozb)
--   • '31' (vystavené) — Dodání zboží prostřední osobou → DPHDP3 ř. 31 (tri_dozb),
--                        a v souhrnném hlášení kód plnění 2.
--
-- Obě plnění jsou bez DPH (osvobozeno / nezdaňuje prostřední osoba), kh_section = NULL
-- (do KH nepatří, vykazuje se přes SHV).
--
-- Idempotence: INSERT ... SELECT ... WHERE NOT EXISTS (unikátní index na (supplier_id, code)
-- nechytá NULL supplier_id — NULL je v MariaDB unikátně distinct, proto explicitní guard).

SET NAMES utf8mb4;

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, display_order)
SELECT NULL, '30', 'Pořízení zboží prostřední osobou při třístranném obchodu (§ 17)', 'purchase', '30', NULL, 0.00, 63
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '30');

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, display_order)
SELECT NULL, '31', 'Dodání zboží prostřední osobou při třístranném obchodu (§ 17)', 'sale', '31', NULL, 0.00, 23
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '31');
