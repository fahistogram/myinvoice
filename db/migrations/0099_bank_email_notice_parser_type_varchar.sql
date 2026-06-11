-- MyInvoice.cz - FR-58 parser registry pro bankovní e-mailová avíza
--
-- parser_type převádíme z ENUM na VARCHAR, aby registry parserů šla rozšiřovat
-- přes cfg.php a DI container bez další změny schématu pro každý nový parser.
-- Systémové parsery dodávají svůj provider z kódu; DB provider zůstává pro
-- regex/custom konfigurace z UI. Starý globální Raiffeisenbank seed z migrace
-- 0096 už je systémový provider z parseru, proto ho odstraníme z DB.

SET NAMES utf8mb4;

ALTER TABLE bank_email_notice_providers
  MODIFY COLUMN parser_type VARCHAR(80) NOT NULL DEFAULT 'regex';

ALTER TABLE bank_email_account_mappings
  ADD COLUMN IF NOT EXISTS provider_code VARCHAR(80) NULL AFTER provider_id,
  ADD KEY IF NOT EXISTS idx_beam_provider_code (provider_code);

UPDATE bank_email_account_mappings m
  JOIN bank_email_notice_providers p ON p.id = m.provider_id
   SET m.provider_code = p.code
 WHERE p.supplier_id IS NULL
   AND p.code = 'raiffeisenbank'
   AND p.parser_type = 'raiffeisenbank'
   AND m.provider_code IS NULL;

DELETE FROM bank_email_notice_providers
 WHERE supplier_id IS NULL
   AND code = 'raiffeisenbank'
   AND parser_type = 'raiffeisenbank';
