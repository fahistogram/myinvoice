-- 0055 — Příznaky daňové uznatelnosti přijaté faktury
--
-- Dva NEZÁVISLÉ příznaky na úrovni přijaté faktury:
--
--   vat_deduction  — nárok na odpočet DPH:
--     'full'         = plný nárok (default, dosavadní chování)
--     'none'         = bez nároku (reprezentace, osobní spotřeba…) → faktura
--                      NEVSTUPUJE do Knihy DPH ani do DPHDP3 / KH (jen účetní evidence)
--     'proportional' = krácený / poměrný odpočet (§75) — odpočet se zkrátí o
--                      procento z vat_deduction_percent (migrace 0056)
--
--   tax_deductible — daňová uznatelnost nákladu pro daň z příjmů (DPFO/DPPO):
--     1 = uznatelný (default), 0 = neuznatelný (nevstupuje do nákladů v IncomeTaxBuilder)
--
-- Pojmy jsou ortogonální: faktura může mít odpočitatelné DPH a být daňově
-- neuznatelná, i naopak.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (MariaDB native).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS vat_deduction ENUM('full','none','proportional') NOT NULL DEFAULT 'full' AFTER vat_classification_code,
  ADD COLUMN IF NOT EXISTS tax_deductible TINYINT(1) NOT NULL DEFAULT 1 AFTER vat_deduction;
