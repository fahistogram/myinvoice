-- MyInvoice.cz — Číselník ročních daňových konstant (admin-editovatelný)
--
-- Override defaultů z TaxConstants.php (ten je jediný zdroj v kódu + fallback).
-- Když pro rok řádek není, engine spadne na TaxConstants::forYear(). Editor
-- v Číselnících sem ukládá úpravy (sazby/limity se mění každý rok); reset = smazání řádku.
--
-- Globální (NE per-supplier) — daňové konstanty jsou národní. Bez seedu: PHP drží
-- ověřené defaulty, do DB se ukládá jen override (re-run migrace tak nepřepíše editace).
--
-- Idempotence: MariaDB-native IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tax_constants (
    year       SMALLINT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Daňový rok',
    data       JSON NOT NULL COMMENT 'Konstanty roku (tvar TaxConstants::forYear)',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
