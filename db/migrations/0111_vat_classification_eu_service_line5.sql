-- 0111: Rozlišení „služba z EU" (ř.5/6) vs „služba ze 3. země / od neusazené osoby" (ř.12/13).
--
-- DPHDP3 dělí přijaté služby s místem plnění dle § 9 odst. 1 na dva řádky:
--   • ř.5/6  — od osoby registrované k dani v JINÉM ČLENSKÉM STÁTĚ (EU),
--   • ř.12/13 — ostatní (od osoby neusazené v tuzemsku, typicky 3. země).
--
-- Seed dosud nesl jen kód 24 (ř.12) s labelem „Přijetí služby z jiného členského
-- státu EU" — tj. EU služba padala na ř.12 místo ř.5 (daňově net = 0, ale špatný
-- řádek). Mimo-EU služby (Anthropic, GitHub, Foxit) přitom defaultně dostávaly kód
-- 25 (ř.7 dovoz ZBOŽÍ), což je taky špatně.
--
-- Náprava modelu:
--   • kód 24  → ZŮSTÁVÁ na ř.12, ale relabel na „3. země / neusazená osoba"
--               (sem patří USA SaaS dle účetní praxe — „43 ř.012 dovoz služby"),
--   • NOVÝ kód 24e → EU služba na ř.5 (+ mirror odpočet ř.43).
--
-- Klasifikaci dokladů (kód 25→24, EU→24e, doplnění odpočtu) řeší samostatný
-- backfill api/bin/backfill-foreign-reverse-charge.php; importní logika
-- (defaultClassificationCode, AiPdfExtractor) je opravena v kódu.
--
-- Idempotentní: relabel guardovaný na starý label, INSERT jen když kód chybí.
-- Sahá pouze na globální seed (supplier_id IS NULL).

SET NAMES utf8mb4;

-- Relabel kódu 24 na 3. zemi / neusazenou osobu (řádek ř.12 se nemění)
UPDATE vat_classifications
   SET label = 'Přijetí služby ze 3. země / od osoby neusazené v tuzemsku (§ 9 odst. 1) – ř.12'
 WHERE supplier_id IS NULL
   AND code = '24'
   AND label = 'Přijetí služby z jiného členského státu EU';

-- Nový kód 24e: přijetí služby z JČS (EU) → ř.5 + mirror odpočet ř.43
INSERT INTO vat_classifications
    (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary,
     kh_section, vat_rate, is_reverse_charge, display_order)
SELECT NULL, '24e', 'Přijetí služby z jiného členského státu EU (§ 9 odst. 1) – ř.5',
       'purchase', '5', '43', NULL, 21.00, 1, 24
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM vat_classifications WHERE code = '24e' AND supplier_id IS NULL
);
