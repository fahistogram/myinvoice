-- MyInvoice.cz — Pravidelné fakturace: „Otevřený koncept"
--
-- Model „fixní SLA + průběžné vícepráce":
--   draft_open_mode = 'period_start' → cron vytvoří koncept faktury na ZAČÁTKU
--   fakturovaného období (1. den měsíce next_run_date), NEvystaví ho. Uživatel
--   pak celý měsíc edituje výkaz práce na tomto konceptu (výkaz je editovatelný
--   jen v draftu). V den next_run_date (typicky konec měsíce) cron koncept
--   uzavře, vystaví a volitelně odešle — issue_date i DUZP zůstávají = plánovaný
--   konec měsíce (next_run_date), bez ohledu na to, kdy cron reálně běžel.
--
--   draft_open_mode = 'at_issue' (default) = původní chování (koncept vzniká až
--   v okamžiku vystavení). Default zachovává zpětnou kompatibilitu.
--
-- Reminder: den(y) před vystavením se dodavateli pošle připomínka „koncept se
--   zítra vystaví, doplň vícepráce". reminder_days_before = počet dní předem,
--   last_reminder_date = guard proti opakovanému odeslání (per období).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (MariaDB native).

SET NAMES utf8mb4;

ALTER TABLE recurring_invoice_templates
  ADD COLUMN IF NOT EXISTS draft_open_mode
    ENUM('at_issue','period_start')
    NOT NULL DEFAULT 'at_issue'
    AFTER tax_date_mode,
  ADD COLUMN IF NOT EXISTS reminder_days_before
    TINYINT UNSIGNED NOT NULL DEFAULT 1
    AFTER draft_open_mode,
  ADD COLUMN IF NOT EXISTS last_reminder_date
    DATE NULL
    AFTER reminder_days_before;
