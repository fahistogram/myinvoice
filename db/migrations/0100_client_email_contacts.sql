-- MyInvoice.cz - #86 e-mailové kontakty odběratele podle účelu
--
-- Více e-mailů per klient s účely použití (communication/documents/reminders/
-- approvals) a typem příjemce (to/cc/bcc). Účely jsou JSON sloupec `usages`
-- (vzor purchase_invoices.vat_overrides) — resolver čte kontakty vždy per
-- klient a filtruje v PHP, M:N tabulka nemá SQL konzumenta.
--
-- ŽÁDNÁ datová migrace main_email → kontakty (záměr): fallback je virtuální
-- v RecipientResolver — bez kontaktů platí přesně dosavadní logika
-- (main_email + project_billing_emails dle typu zprávy). Tím nevzniká drift
-- při pozdější změně clients.main_email.
--
-- projects.billing_emails_mode řídí kombinaci s e-maily zakázky:
--   auto    = dosavadní per-typ sémantika (doklady/upomínky: append;
--             schvalování: replace) — DEFAULT, 100% zpětná kompatibilita
--   append  = e-maily zakázky se vždy přidají k příjemcům dle účelu
--   replace = e-maily zakázky (jsou-li) příjemce dle účelu nahradí

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS client_email_contacts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id     BIGINT UNSIGNED NOT NULL,
  email         VARCHAR(190) NOT NULL,
  label         VARCHAR(80) NULL,                 -- volitelný popisek („účetní oddělení")
  contact_name  VARCHAR(120) NULL,                -- jméno kontaktní osoby
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  -- [{"usage":"documents","recipient":"to"}, …]; usage ∈ communication|documents|
  -- reminders|approvals, recipient ∈ to|cc|bcc. Validuje aplikace (repo).
  usages        JSON NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cec_client (client_id),
  CONSTRAINT fk_cec_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE projects
  ADD COLUMN IF NOT EXISTS billing_emails_mode ENUM('auto','append','replace') NOT NULL DEFAULT 'auto' AFTER payment_due_days;
