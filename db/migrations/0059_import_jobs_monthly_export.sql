-- MyInvoice.cz — Měsíční export jako background job
--
-- Rozšiřuje sdílenou tabulku import_jobs o nový zdroj 'monthly_export' a o sloupce
-- pro výsledný soubor (ZIP), který si uživatel po dokončení jobu stáhne. Import joby
-- žádný soubor neprodukují (jen DB záznamy) — export ano, proto result_*.
--
-- Idempotence: MODIFY ENUM je deklarativní (opakování nastaví stejnou definici);
-- ADD COLUMN IF NOT EXISTS je MariaDB-native.

SET NAMES utf8mb4;

-- Přidej 'monthly_export' do source ENUM (zachovej existující hodnoty).
ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM('idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export') NOT NULL;

-- Výsledný soubor (relativní cesta v rámci storage/monthly-exports, název, velikost, MIME).
ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS result_path VARCHAR(255) NULL AFTER last_error;
ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS result_name VARCHAR(255) NULL AFTER result_path;
ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS result_size BIGINT UNSIGNED NULL AFTER result_name;
ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS result_mime VARCHAR(100) NULL AFTER result_size;
