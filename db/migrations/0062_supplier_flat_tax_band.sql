-- MyInvoice.cz — Paušální daň (flat tax) pásmo per supplier
--
-- Pro OSVČ v paušálním režimu (§ 2a a § 7a ZDP) sledujeme, do kterého pásma
-- spadá, abychom mohli na stránce Tržby hlídat blížící se překročení ročního
-- limitu příjmů pro dané pásmo a varovat před vypadnutím z režimu:
--
--   band1: do 1 000 000 Kč/rok
--   band2: do 1 500 000 Kč/rok
--   band3: do 2 000 000 Kč/rok
--   none:  není v paušálu (klasický daňový režim) — výchozí
--
-- Podmínka §7a ZDP: paušalista NESMÍ být plátce DPH — kombinaci
-- is_vat_payer=1 + flat_tax_band<>'none' odmítá SettingsAction (422).
--
-- Limity pásem žijí v PHP (SummaryAction::FLAT_TAX_BANDS), ne v DB — mění se
-- s rokem a nemá smysl kvůli nim migrovat historická data.
--
-- Idempotence: MariaDB native IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS flat_tax_band ENUM('none','band1','band2','band3') NOT NULL DEFAULT 'none'
        COMMENT 'Paušální daň pásmo. none = klasický režim, band1/2/3 = paušál s limitem příjmů 1M/1.5M/2M Kč/rok.';
