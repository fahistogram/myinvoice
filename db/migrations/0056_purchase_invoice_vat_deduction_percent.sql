-- 0056 — Procento kráceného odpočtu (§75 poměrný odpočet)
--
-- Když vat_deduction = 'proportional' (migrace 0055), odpočet DPH se uplatní jen
-- v poměrné výši — uživatel zadá procento (např. auto 70 % pro ekonomickou
-- činnost). VatLedgerService škáluje základ i daň odpočtu tímto procentem;
-- zbytek je nedaňová část (mimo odpočet).
--
-- Pro 'full' / 'none' se sloupec ignoruje (drží default 100).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (MariaDB native).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS vat_deduction_percent DECIMAL(5,2) NOT NULL DEFAULT 100.00 AFTER vat_deduction;
