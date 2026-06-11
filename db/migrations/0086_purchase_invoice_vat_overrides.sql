-- MyInvoice.cz — Ruční override rekapitulace DPH u přijatých faktur (§ 73 ZDPH)
--
-- Motivace (issue #82): u přijaté faktury je nárok na odpočet svázaný s ČÁSTKOU DANĚ
-- UVEDENOU NA DOKLADU dodavatele (§ 73 odst. 6 ZDPH). Když dodavatel zaokrouhlí DPH
-- jinak než náš kalkulátor (rozdíl ±0,01 Kč je zákonem tolerovaný, viz § 37), chceme
-- umět rekapitulaci DPH zadat PŘESNĚ DLE DOKLADU — základ i daň, per sazba.
--
-- DATOVÝ MODEL: per-doklad JSON pole `vat_overrides` na purchase_invoices:
--   [{"rate":21.00,"base":151.50,"vat":31.81}, ...]
-- NULL = žádný override → kalkulátor počítá standardně (zpětně kompatibilní).
-- Override se po recompute "zapeče" do uložených řádkových total_without_vat/total_vat/
-- total_with_vat (reziduum se dorovná na nejsilnějším řádku dané sazby), takže
-- VatLedgerService (čte uložené řádkové totály) zůstává beze změny.
--
-- Idempotence: MariaDB-native ADD COLUMN IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS vat_overrides JSON NULL
        COMMENT 'Ruční override rekapitulace DPH dle dokladu (§73): [{rate,base,vat}]';
