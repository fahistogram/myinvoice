-- MyInvoice.cz — Per-supplier kopie odchozích e-mailů dodavateli (CC/BCC).
--
-- Nahrazuje globální cfg flagy smtp.cc_supplier_on_send, smtp.cc_supplier_on_reminder,
-- approval.cc_supplier_on_approval, approval.cc_supplier_on_approval_reminder.
--
-- self_copy = JSON objekt s klíči dle typů zpráv RecipientResolveru
-- (typ JSON — symetrie s client_email_contacts.usages a project_billing_emails.usages):
--   {"documents":"cc","reminders":"off","approvals":"bcc"}
-- Hodnoty: 'off' | 'cc' | 'bcc'. Chybějící klíč (nebo NULL sloupec) = fallback
-- na cfg flag — cfg zůstává živý default, supplier přepisuje jen co explicitně
-- nastaví (vzor invoice_number_format). Klíč `approvals` platí jednotně pro
-- žádost o schválení i schvalovací upomínku; jemnější rozlišení obou cfg flagů
-- zůstává jen ve fallbacku.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS self_copy JSON NULL DEFAULT NULL AFTER payment_thanks_attach_paid_pdf;
