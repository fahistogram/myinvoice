-- MyInvoice.cz — Per-supplier přepínač „zobrazit i název firmy vedle loga" v PDF faktuře
--
-- Když má dodavatel nahrané logo a zapnutý branding, default je jen logo.
-- Tenhle opt-in přepínač vykreslí vedle loga i obchodní/firemní název — vhodné
-- pro malá nebo symbolová loga, která název neobsahují.
--
-- Idempotent přes MariaDB native `IF NOT EXISTS`.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS pdf_logo_show_name TINYINT(1) NOT NULL DEFAULT 0 AFTER email_accent_color;
