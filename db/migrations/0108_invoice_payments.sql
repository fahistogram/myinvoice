-- MyInvoice.cz — Částečné úhrady faktur / evidence plateb (#89) + daňové doklady k přijaté platbě
--
-- Nová tabulka invoice_payments: N:1 evidence plateb k vydaným fakturám a proformám.
-- Jedna faktura může mít víc plateb (splátky, více převodů, avíza); každá platba může
-- nést vazbu na bankovní transakci (UNIQUE — jedna tx vytvoří nejvýš jednu platbu,
-- idempotence rematch) a u proformy na vystavený daňový doklad k přijaté platbě.
--
--   • invoices.paid_total — stored suma plateb (udržuje InvoicePaymentService);
--     zbývá-k-úhradě = amount_to_pay - paid_total. Formule amount_to_pay se NEMĚNÍ
--     (je to statické „K úhradě" dokladu, tiskne se na PDF).
--   • invoices.status ENUM se NEROZŠIŘUJE — platební stav (unpaid/partially_paid/
--     paid/overpaid) je odvozená dimenze z paid_total vs. amount_to_pay.
--   • invoice_type + 'tax_document' — daňový doklad k přijaté platbě (§ 28/2 písm. d
--     ZDPH), DUZP = datum platby, DPH shora koeficientem (§ 37), parent_invoice_id
--     = proforma. Čísluje se v řadě faktur.
--
-- Backfill: historické zaplacené faktury/proformy dostanou jednu 'legacy' platbu
-- na plnou částku (amount_to_pay) k datu paid_at, ať mají konzistentní paid_total
-- a box Platby není prázdný. Finální doklady s amount_to_pay <= 0 se přeskakují
-- (uhrazeno zálohou, žádná platba neproběhla).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, MODIFY ENUM
-- (re-run no-op), backfill přes NOT EXISTS, paid_total = přepočet z tabulky.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS invoice_payments (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Multi-tenant scope — konzistentní s ostatními tabulkami (TINYINT).
    supplier_id              TINYINT UNSIGNED NOT NULL,

    invoice_id               BIGINT UNSIGNED NOT NULL,

    paid_on                  DATE NOT NULL,

    -- Částka v měně faktury (> 0). CZK platba cizoměnové faktury se přepočítává
    -- kurzem faktury už při záznamu (shodně se StatementMatcher).
    amount                   DECIMAL(12,2) NOT NULL,

    -- Denormalizovaný kód měny faktury v okamžiku platby (audit).
    currency                 CHAR(3) NOT NULL,

    variable_symbol          VARCHAR(20) NULL,

    -- Reference banky / avíza (bank_ref transakce, číslo avíza…).
    bank_reference           VARCHAR(120) NULL,

    note                     VARCHAR(255) NULL,

    -- Provenience: manual = ruční záznam (modal Částečná úhrada),
    -- mark_paid = zkratka „Faktura zaplacena" (platba na zbytek),
    -- bank = bankovní párování (GPC import i e-mailové avízo),
    -- legacy = backfill z dob před evidencí plateb.
    source                   ENUM('manual','mark_paid','bank','legacy') NOT NULL DEFAULT 'manual',

    bank_transaction_id      BIGINT UNSIGNED NULL,

    -- Daňový doklad k přijaté platbě vystavený k této platbě (jen proformy).
    tax_document_invoice_id  BIGINT UNSIGNED NULL,

    created_by               BIGINT UNSIGNED NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_invpay_supplier  (supplier_id),
    KEY idx_invpay_invoice   (invoice_id),
    KEY idx_invpay_taxdoc    (tax_document_invoice_id),

    -- Jedna bankovní transakce smí založit nejvýš jednu platbu (idempotence rematch).
    UNIQUE KEY uq_invpay_bank_tx (bank_transaction_id),

    CONSTRAINT fk_invpay_supplier FOREIGN KEY (supplier_id)
        REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_invpay_invoice  FOREIGN KEY (invoice_id)
        REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invpay_bank_tx  FOREIGN KEY (bank_transaction_id)
        REFERENCES bank_transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_invpay_taxdoc   FOREIGN KEY (tax_document_invoice_id)
        REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_invpay_user     FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL,

    CONSTRAINT chk_invpay_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS paid_total DECIMAL(12,2) NOT NULL DEFAULT 0
        COMMENT 'Suma evidovaných plateb (invoice_payments) v měně faktury; udržuje InvoicePaymentService'
        AFTER advance_paid_amount;

-- Nový typ dokladu: daňový doklad k přijaté platbě (záloze). MODIFY je idempotentní.
ALTER TABLE invoices
    MODIFY invoice_type ENUM('invoice','proforma','credit_note','cancellation','tax_document')
        NOT NULL DEFAULT 'invoice';

-- Backfill: jedna 'legacy' platba pro historicky zaplacené doklady s kladnou částkou
-- k úhradě (finální doklady kryté zálohou mají amount_to_pay <= 0 → bez platby).
INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
SELECT i.supplier_id,
       i.id,
       COALESCE(i.paid_at, i.issue_date),
       i.amount_to_pay,
       COALESCE(cur.code, 'CZK'),
       'legacy'
  FROM invoices i
  LEFT JOIN currencies cur ON cur.id = i.currency_id
 WHERE i.status = 'paid'
   AND i.invoice_type IN ('invoice', 'proforma')
   AND i.amount_to_pay > 0
   AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.id);

-- Přepočet paid_total z tabulky (idempotentní — invariant sumy).
UPDATE invoices i
  LEFT JOIN (SELECT invoice_id, SUM(amount) AS s FROM invoice_payments GROUP BY invoice_id) p
    ON p.invoice_id = i.id
   SET i.paid_total = COALESCE(p.s, 0)
 WHERE i.paid_total <> COALESCE(p.s, 0);
