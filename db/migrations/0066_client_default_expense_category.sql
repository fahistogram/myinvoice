-- MyInvoice.cz — Default kategorie nákladu na dodavateli
--
-- Dodavatel (clients.is_vendor=1) může mít přednastavenou výchozí kategorii
-- nákladu. Použití:
--   * Při zakládání nové přijaté faktury se předvyplní (jako už teď měna/jazyk).
--   * Při uložení dodavatele s nastavenou kategorií se default jednorázově
--     doplní do VŠECH jeho přijatých faktur, které kategorii nemají vyplněnou
--     (expense_category_id IS NULL). Faktury s vybranou kategorií zůstanou beze změny.
--
-- FK záměrně nepřidáváme — konzistentní s purchase_invoices.expense_category_id
-- (migrace 0035), kde sloupec také FK nemá. Mazání kategorie ošetřuje aplikace.
--
-- Idempotence: MariaDB-native `IF NOT EXISTS`. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS default_expense_category_id INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Výchozí kategorie nákladu pro přijaté faktury tohoto dodavatele. NULL = bez defaultu.'
    AFTER note;
