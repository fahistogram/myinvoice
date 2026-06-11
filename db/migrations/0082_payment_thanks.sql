-- MyInvoice.cz — Děkovný e-mail za úhradu faktury (issue #57).
--
-- Per-supplier přepínače (vše default vypnuto, ať se po updatu nezačnou posílat
-- nové e-maily bez vědomého zapnutí) + idempotenční stopa na faktuře.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS payment_thanks_enabled         TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_send_reminders,
  ADD COLUMN IF NOT EXISTS payment_thanks_auto_send       TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_thanks_enabled,
  ADD COLUMN IF NOT EXISTS payment_thanks_default_checked  TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_thanks_auto_send,
  ADD COLUMN IF NOT EXISTS payment_thanks_attach_paid_pdf  TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_thanks_default_checked;

-- Idempotence: děkovný e-mail se automaticky pošle jen pokud sent_at IS NULL.
-- Při unmark-paid se ZÁMĚRNĚ nemaže (znovuodeslání je vědomá ruční akce).
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS payment_thanks_sent_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS payment_thanks_sent_to VARCHAR(512) NULL DEFAULT NULL;
