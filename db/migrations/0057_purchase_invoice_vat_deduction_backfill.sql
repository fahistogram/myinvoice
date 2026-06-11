-- 0057 — Backfill prázdného vat_deduction na 'full'
--
-- Bug (opraveno v PurchaseInvoiceRepository::createDraft/updateDraft): ternár
-- vyhodnocoval `(string) $data['vat_deduction']` bez `?? 'full'`, takže importy
-- které vat_deduction v payloadu neposílaly (AI, ISDOC, inbox scanner) ukládaly
-- do ENUM sloupce prázdný řetězec '' místo defaultu 'full'.
--
-- Funkčně se '' chovalo jako 'full' (reporty filtrují `<> 'none'` a `= 'proportional'`),
-- ale v editoru se zobrazovalo jako nevyplněné. Tato migrace srovná data.
--
-- Idempotence: UPDATE jen řádků kde hodnota není platný enum člen — opakovaný běh nic nezmění.

SET NAMES utf8mb4;

UPDATE purchase_invoices
   SET vat_deduction = 'full'
 WHERE vat_deduction NOT IN ('full', 'none', 'proportional');
