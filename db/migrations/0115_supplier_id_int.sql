-- 0115_supplier_id_int.sql
-- Rozšíření tenant klíče supplier.id (a všech navázaných supplier_id) z TINYINT UNSIGNED
-- (strop 255) na INT UNSIGNED (strop ~4,3 mld). Hostovaná multi-tenant verze by jinak
-- narazila na 256. dodavateli (AUTO_INCREMENT selže). Sjednocuje šířku s ostatními
-- entitními PK (ty jsou BIGINT UNSIGNED; supplier zůstával jediný TINYINT).
--
-- Idempotentní (DDL nejde do transakce → musí jít bezpečně zopakovat po pádu uprostřed):
--   • DROP FOREIGN KEY IF EXISTS         — opakovaný běh = no-op
--   • MODIFY ... INT UNSIGNED            — samo idempotentní (potvrdí typ)
--   • ADD ... FOREIGN KEY IF NOT EXISTS  — opakovaný běh = no-op
-- Striktní pořadí fází (DROP všech → MODIFY → ADD všech) zaručuje, že při ADD už jsou
-- typy na obou stranách shodné, ať se migrace spustí celá nebo dojede po přerušení.
--
-- Rozsah: 36 FK (32 jednoduchých → supplier.id, 4 composite → signing_profiles(supplier_id,id)),
--         supplier.id + 35 sloupců supplier_id.

-- ─── 1) DROP: composite FK na signing_profiles(supplier_id, id) ──────────────────
ALTER TABLE pdf_signature_output_settings DROP FOREIGN KEY IF EXISTS fk_pdf_sig_output_default_profile;
ALTER TABLE signature_document_overrides  DROP FOREIGN KEY IF EXISTS fk_sig_doc_admin_profile;
ALTER TABLE signature_role_profiles       DROP FOREIGN KEY IF EXISTS fk_sig_role_profile;
ALTER TABLE signature_user_profiles       DROP FOREIGN KEY IF EXISTS fk_sig_user_profile;

-- ─── 1) DROP: jednoduché FK na supplier.id ──────────────────────────────────────
ALTER TABLE api_tokens                    DROP FOREIGN KEY IF EXISTS fk_apitok_supplier;
ALTER TABLE bank_email_account_mappings   DROP FOREIGN KEY IF EXISTS fk_beam_supplier;
ALTER TABLE bank_email_imap_settings      DROP FOREIGN KEY IF EXISTS fk_bei_supplier;
ALTER TABLE bank_email_notice_providers   DROP FOREIGN KEY IF EXISTS fk_benp_supplier;
ALTER TABLE bank_email_processed_messages DROP FOREIGN KEY IF EXISTS fk_bepm_supplier;
ALTER TABLE cars                          DROP FOREIGN KEY IF EXISTS fk_cars_supplier;
ALTER TABLE clients                       DROP FOREIGN KEY IF EXISTS fk_cli_supplier;
ALTER TABLE crm_action_item_dismissals    DROP FOREIGN KEY IF EXISTS fk_aid_supplier;
ALTER TABLE crm_monthly_summary           DROP FOREIGN KEY IF EXISTS fk_cms_supplier;
ALTER TABLE currencies                    DROP FOREIGN KEY IF EXISTS fk_cur_supplier;
ALTER TABLE expense_categories            DROP FOREIGN KEY IF EXISTS fk_ec_supplier;
ALTER TABLE fuelings                      DROP FOREIGN KEY IF EXISTS fk_fuelings_supplier;
ALTER TABLE import_jobs                   DROP FOREIGN KEY IF EXISTS fk_ij_supplier;
ALTER TABLE invoices                      DROP FOREIGN KEY IF EXISTS fk_inv_supplier;
ALTER TABLE invoice_counters              DROP FOREIGN KEY IF EXISTS fk_ic_supplier;
ALTER TABLE invoice_payments              DROP FOREIGN KEY IF EXISTS fk_invpay_supplier;
ALTER TABLE logbook_fuel_scans            DROP FOREIGN KEY IF EXISTS fk_fuelscan_supplier;
ALTER TABLE payment_matches               DROP FOREIGN KEY IF EXISTS fk_pm_supplier;
ALTER TABLE pdf_signature_output_settings DROP FOREIGN KEY IF EXISTS fk_pdf_sig_output_supplier;
ALTER TABLE purchase_invoices             DROP FOREIGN KEY IF EXISTS fk_pi_supplier;
ALTER TABLE purchase_invoice_counters     DROP FOREIGN KEY IF EXISTS fk_pic_supplier;
ALTER TABLE recurring_invoice_templates   DROP FOREIGN KEY IF EXISTS fk_rit_supplier;
ALTER TABLE revenue_categories            DROP FOREIGN KEY IF EXISTS fk_rc_supplier;
ALTER TABLE signature_document_overrides  DROP FOREIGN KEY IF EXISTS fk_sig_doc_supplier;
ALTER TABLE signature_role_profiles       DROP FOREIGN KEY IF EXISTS fk_sig_role_supplier;
ALTER TABLE signature_user_profiles       DROP FOREIGN KEY IF EXISTS fk_sig_user_supplier;
ALTER TABLE signing_profiles              DROP FOREIGN KEY IF EXISTS fk_signing_profile_supplier;
ALTER TABLE signing_settings              DROP FOREIGN KEY IF EXISTS fk_signing_settings_supplier;
ALTER TABLE tax_profiles                  DROP FOREIGN KEY IF EXISTS fk_taxprofile_supplier;
ALTER TABLE trips                         DROP FOREIGN KEY IF EXISTS fk_trips_supplier;
ALTER TABLE trip_categories               DROP FOREIGN KEY IF EXISTS fk_tripcat_supplier;
ALTER TABLE vat_classifications           DROP FOREIGN KEY IF EXISTS fk_vatcls_supplier;

-- ─── 2) MODIFY: rodičovský PK ───────────────────────────────────────────────────
ALTER TABLE supplier MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- ─── 2) MODIFY: 35 sloupců supplier_id (zachována nullability + komentář) ────────
ALTER TABLE activity_log                  MODIFY supplier_id INT UNSIGNED NULL;
ALTER TABLE api_tokens                    MODIFY supplier_id INT UNSIGNED NULL;
ALTER TABLE bank_email_notice_providers   MODIFY supplier_id INT UNSIGNED NULL;
ALTER TABLE vat_classifications           MODIFY supplier_id INT UNSIGNED NULL COMMENT 'NULL = globální/seed (sdíleno), jinak per-tenant override';
ALTER TABLE bank_email_account_mappings   MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE bank_email_imap_settings      MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE bank_email_processed_messages MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE cars                          MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE clients                       MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE crm_action_item_dismissals    MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE crm_monthly_summary           MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE currencies                    MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE expense_categories            MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE fuelings                      MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE import_jobs                   MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE invoices                      MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE invoice_counters              MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE invoice_payments              MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE logbook_fuel_scans            MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE payment_matches               MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE payment_orders                MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE pdf_signature_output_settings MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE purchase_invoices             MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE purchase_invoice_counters     MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE recurring_invoice_templates   MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE revenue_categories            MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE signature_document_overrides  MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE signature_role_profiles       MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE signature_user_profiles       MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE signing_profiles              MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE signing_settings              MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE tax_profiles                  MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE trips                         MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE trip_categories               MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE work_report_links             MODIFY supplier_id INT UNSIGNED NOT NULL;

-- ─── 3) ADD: jednoduché FK zpět na supplier.id (ON DELETE dle původního stavu) ──
ALTER TABLE api_tokens                    ADD CONSTRAINT fk_apitok_supplier            FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE bank_email_account_mappings   ADD CONSTRAINT fk_beam_supplier              FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE bank_email_imap_settings      ADD CONSTRAINT fk_bei_supplier               FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE bank_email_notice_providers   ADD CONSTRAINT fk_benp_supplier              FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE bank_email_processed_messages ADD CONSTRAINT fk_bepm_supplier              FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE cars                          ADD CONSTRAINT fk_cars_supplier              FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE clients                       ADD CONSTRAINT fk_cli_supplier               FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE crm_action_item_dismissals    ADD CONSTRAINT fk_aid_supplier               FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE crm_monthly_summary           ADD CONSTRAINT fk_cms_supplier               FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE currencies                    ADD CONSTRAINT fk_cur_supplier               FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE expense_categories            ADD CONSTRAINT fk_ec_supplier                FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE fuelings                      ADD CONSTRAINT fk_fuelings_supplier          FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE import_jobs                   ADD CONSTRAINT fk_ij_supplier                FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE invoices                      ADD CONSTRAINT fk_inv_supplier               FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE invoice_counters              ADD CONSTRAINT fk_ic_supplier                FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE invoice_payments              ADD CONSTRAINT fk_invpay_supplier            FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE logbook_fuel_scans            ADD CONSTRAINT fk_fuelscan_supplier          FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE payment_matches               ADD CONSTRAINT fk_pm_supplier                FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE pdf_signature_output_settings ADD CONSTRAINT fk_pdf_sig_output_supplier    FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE purchase_invoices             ADD CONSTRAINT fk_pi_supplier                FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE purchase_invoice_counters     ADD CONSTRAINT fk_pic_supplier               FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE recurring_invoice_templates   ADD CONSTRAINT fk_rit_supplier               FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE revenue_categories            ADD CONSTRAINT fk_rc_supplier                FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;
ALTER TABLE signature_document_overrides  ADD CONSTRAINT fk_sig_doc_supplier           FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE signature_role_profiles       ADD CONSTRAINT fk_sig_role_supplier          FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE signature_user_profiles       ADD CONSTRAINT fk_sig_user_supplier          FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE signing_profiles              ADD CONSTRAINT fk_signing_profile_supplier   FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE signing_settings              ADD CONSTRAINT fk_signing_settings_supplier  FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE tax_profiles                  ADD CONSTRAINT fk_taxprofile_supplier        FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE trips                         ADD CONSTRAINT fk_trips_supplier             FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE trip_categories               ADD CONSTRAINT fk_tripcat_supplier           FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE vat_classifications           ADD CONSTRAINT fk_vatcls_supplier            FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT;

-- ─── 3) ADD: composite FK zpět na signing_profiles(supplier_id, id) ─────────────
ALTER TABLE pdf_signature_output_settings ADD CONSTRAINT fk_pdf_sig_output_default_profile FOREIGN KEY IF NOT EXISTS (supplier_id, default_profile_id) REFERENCES signing_profiles(supplier_id, id) ON DELETE RESTRICT;
ALTER TABLE signature_document_overrides  ADD CONSTRAINT fk_sig_doc_admin_profile         FOREIGN KEY IF NOT EXISTS (supplier_id, admin_profile_id)   REFERENCES signing_profiles(supplier_id, id) ON DELETE RESTRICT;
ALTER TABLE signature_role_profiles       ADD CONSTRAINT fk_sig_role_profile              FOREIGN KEY IF NOT EXISTS (supplier_id, profile_id)         REFERENCES signing_profiles(supplier_id, id) ON DELETE CASCADE;
ALTER TABLE signature_user_profiles       ADD CONSTRAINT fk_sig_user_profile              FOREIGN KEY IF NOT EXISTS (supplier_id, profile_id)         REFERENCES signing_profiles(supplier_id, id) ON DELETE CASCADE;
