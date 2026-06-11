-- MyInvoice.cz — Splatnost zakázky: jednotka (dny vs. kalendářní měsíc)
--
-- Navazuje na 0070_payment_due_unit.sql (supplier + clients). Zakázka už má
-- `payment_due_days` (NOT NULL, vždy konkrétní hodnota a v editoru faktury
-- přebíjí klienta i supplier). Doplňujeme jednotku, aby i zakázka mohla mít
-- splatnost typu „kalendářní měsíc".
--
--   * projects.payment_due_unit — 'days' nebo 'month'. NULL = dny (zpětná
--     kompatibilita: existující zakázky mají NULL → chovají se jako dosud).
--     Při 'month' se `payment_due_days` interpretuje jako počet kalendářních
--     měsíců (typicky 1) s last-day overflow handlingem (31.1. + 1 měsíc →
--     28./29.2., ne 3.3.).
--
-- Idempotence: MariaDB native IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS payment_due_unit ENUM('days','month') NULL DEFAULT NULL
        COMMENT 'Jednotka splatnosti zakázky. NULL/days = payment_due_days dní, month = tolik kalendářních měsíců (overflow → poslední den měsíce).'
        AFTER payment_due_days;
