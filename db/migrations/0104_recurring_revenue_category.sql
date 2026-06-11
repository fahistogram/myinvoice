-- MyInvoice.cz — Pevná kategorie tržby na šabloně opakované fakturace (#119)
--
-- Šablona může mít přímo zvolenou kategorii tržby, která PŘEBÍJÍ dynamický
-- fallback při generování faktury. Pořadí: kategorie šablony > výchozí
-- kategorie zakázky > výchozí kategorie zákazníka > NULL.
--
--   * NULL (default, všechny existující šablony) = současné chování beze změny.
--   * Vyplněno = každá vygenerovaná faktura dostane tuto kategorii bez ohledu
--     na pozdější změny defaultů zakázky/zákazníka (stabilní zařazení pro
--     domény/hosting/licence/paušály).
--   * Kategorie se při generování ukládá do invoices.revenue_category_id jako
--     snapshot — pozdější změna šablony už vygenerované faktury nemění.
--
-- FK záměrně nepřidáváme — konzistentní s clients/projects default_revenue_category_id
-- (0073, 0075) a invoices.revenue_category_id. Mazání kategorie ošetřuje aplikace
-- (hard delete jen nepoužité, jinak archived).
--
-- Idempotence: MariaDB-native IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE recurring_invoice_templates
  ADD COLUMN IF NOT EXISTS revenue_category_id INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Pevná kategorie tržby pro generované faktury. NULL = fallback default zakázky > zákazníka.'
    AFTER discount_percent;
