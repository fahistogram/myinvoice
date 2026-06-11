-- MyInvoice.cz — Per-client číselné řady faktur
--
-- Před touto migrací byl varsymbol řízen jen per-supplier templatem (sloupce na
-- supplier.invoice_number_format / proforma_number_format / credit_note_number_format)
-- a counter `invoice_counters` byl scope-ovaný na (supplier_id, invoice_type, period).
--
-- Pro tenanty, kteří fakturují více klientům s historicky odlišnou číselnou řadou
-- (typicky převod z Fakturoidu, kde řada šla per klient), je tahle struktura málo.
-- Přidáváme:
--
--   * clients.{invoice|proforma|credit_note}_number_format — per-client template
--     override. NULL = fallback na supplier-level template (a dál na cfg).
--   * clients.invoice_number_period — per-client period override
--     ('year'|'month'|'none'). NULL = fallback na supplier.invoice_number_period.
--   * invoice_counters.client_id — counter scope. `0` znamená supplier-wide
--     (žádný per-client template, používáme supplier-level template + counter).
--
-- Idempotence: MariaDB-native `IF NOT EXISTS` / `IF EXISTS` guards. Re-run safe.

SET NAMES utf8mb4;

-- ── clients: per-client číselný formát ────────────────────────────────────
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS invoice_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-client template pro vydanou fakturu. NULL = dědit ze supplieru.'
    AFTER note;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS proforma_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-client template pro proformu. NULL = dědit ze supplieru.'
    AFTER invoice_number_format;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS credit_note_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-client template pro dobropis. NULL = dědit ze supplieru.'
    AFTER proforma_number_format;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS invoice_number_period ENUM('year','month','none') NULL DEFAULT NULL
    COMMENT 'Per-client období counteru. NULL = dědit ze supplieru.'
    AFTER credit_note_number_format;

-- ── invoice_counters: rozšířit scope o client_id ──────────────────────────
-- `0` (NOT NULL DEFAULT 0) = supplier-wide counter (existující řádky se tím
-- automaticky převedou). Per-client counter má client_id = clients.id.

ALTER TABLE invoice_counters
  ADD COLUMN IF NOT EXISTS client_id BIGINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT '0 = supplier-wide counter, jinak clients.id pro per-client řadu.'
    AFTER supplier_id;

-- Rozšíření PK o client_id. Kombinovaný DROP+ADD v jednom ALTER je re-runnable:
-- `invoice_counters` má vždy primární klíč, takže DROP PRIMARY KEY uspěje a ADD ho
-- znovu poskládá do cílové podoby (při opakovaném běhu drop+add téhož 4-sloup. PK).
-- Bez PREPARE/EXECUTE — viz konvence idempotentních migrací (MariaDB native).
ALTER TABLE invoice_counters
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (supplier_id, client_id, invoice_type, period);
