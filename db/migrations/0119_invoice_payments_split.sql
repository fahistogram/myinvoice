-- MyInvoice.cz — Sloučená úhrada: jedna příchozí platba spáruje VÍCE vystavených faktur.
--
-- Dosud měla invoice_payments UNIQUE(bank_transaction_id) → jedna bankovní transakce
-- mohla založit nejvýš JEDNU platbu (idempotence rematch u 1:1 párování). Pro sloučenou
-- úhradu (klient zaplatí 2+ faktur jednou platbou, součet sedí, VS nesedí) potřebujeme,
-- aby jedna transakce mohla založit platbu NA KAŽDOU z vybraných faktur.
--
-- Rozšiřujeme unikát na (bank_transaction_id, invoice_id): idempotence zůstává zachovaná
-- per faktura (tatáž transakce nezaloží 2× platbu na stejnou fakturu), ale split na více
-- různých faktur je povolen. Ruční/legacy platby (bank_transaction_id = NULL) nejsou
-- dotčené — NULL hodnoty jsou v UNIQUE indexu vždy navzájem různé.
--
-- Idempotence: ADD UNIQUE IF NOT EXISTS + DROP INDEX IF EXISTS (MariaDB 10.6+).
--
-- POŘADÍ JE DŮLEŽITÉ: na bank_transaction_id visí FK fk_invpay_bank_tx, který vyžaduje
-- index. Starý UNIQUE uq_invpay_bank_tx je jediný → nejdřív přidáme nový složený index
-- (bank_transaction_id je jeho levý sloupec, takže FK pokryje), teprve pak dropneme starý.
-- Jinak: „1553 - Cannot drop index … needed in a foreign key constraint".

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

ALTER TABLE invoice_payments
    ADD UNIQUE KEY IF NOT EXISTS uq_invpay_bank_tx_invoice (bank_transaction_id, invoice_id);

ALTER TABLE invoice_payments
    DROP INDEX IF EXISTS uq_invpay_bank_tx;
