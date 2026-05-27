-- MyInvoice.cz — PDF příloha k bankovnímu výpisu
--
-- K existujícímu GPC výpisu (bank_statements) lze přiložit i PDF verzi výpisu
-- z banky. PDF se ukládá stejně jako GPC (file_content) — jako MEDIUMBLOB přímo
-- v řádku výpisu (konzistentní s migrací 0045, bez diskové cesty / path-traversal
-- plochy). Stahuje se přes samostatný endpoint /api/bank-statements/{id}/pdf.
--
-- Vše nullable — PDF je volitelná příloha, většina výpisů ho mít nebude.
-- pdf_hash slouží k případné deduplikaci/integritě, pdf_size_bytes pro UI.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (MariaDB native).

SET NAMES utf8mb4;

ALTER TABLE bank_statements
  ADD COLUMN IF NOT EXISTS pdf_content MEDIUMBLOB NULL AFTER file_content,
  ADD COLUMN IF NOT EXISTS pdf_name VARCHAR(255) NULL AFTER pdf_content,
  ADD COLUMN IF NOT EXISTS pdf_hash CHAR(64) NULL AFTER pdf_name,
  ADD COLUMN IF NOT EXISTS pdf_size_bytes INT UNSIGNED NULL AFTER pdf_hash,
  ADD COLUMN IF NOT EXISTS pdf_uploaded_at TIMESTAMP NULL AFTER pdf_size_bytes;
