-- MyInvoice.cz — Kniha jízd (vehicle logbook) — auta, jízdy, tankování, kategorie cest
--
-- Nový modul pod menu Dokumenty. Daňová evidence dle § 24/2 ZDP + pokyn GFŘ D-22:
--   • per vozidlo: typ, SPZ, stav tachometru k zahájení (a k 31.12.),
--   • per jízda:   datum, čas, odkud→kam, účel, ujeté km, kategorie (služební/soukromá).
--
-- Tabulky:
--   cars               — číselník automobilů (SPZ, značka, palivo, počáteční tachometr, default auto)
--   trip_categories    — číselník kategorií cest (služební/soukromá; daňová relevance přes is_private)
--   trips              — jednotlivé jízdy
--   fuelings           — tankování (ruční / z přijatých faktur od benzínek / Axigon parser)
--   logbook_fuel_scans — marker „faktura už vytěžena" (parse jen jednou, i pro backfill historie)
--   + clients.is_fuel_station — příznak „benzínka"
--
-- fuelings je EVIDENČNÍ vrstva nad přijatou fakturou — náklad účtuje purchase invoice,
-- fuelings ho jen rozpadá na tankování/auto. Do DPH/statistik/dashboardů NEvstupuje.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, seed přes NOT EXISTS.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ───────────────────────────────────────────────────────────────────────────
-- Automobily (číselník)
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cars (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id          TINYINT UNSIGNED NOT NULL,

    registration         VARCHAR(20)  NOT NULL,             -- SPZ / RZ
    name                 VARCHAR(100) NULL,                 -- volitelný popisek („Octavia firemní")
    brand                VARCHAR(100) NULL,
    model                VARCHAR(100) NULL,
    vin                  VARCHAR(40)  NULL,
    fuel_type            ENUM('diesel','petrol','lpg','cng','electric','hybrid','other') NULL,

    -- Stav tachometru k zahájení evidence (k 1.1. nebo dni pořízení) — pro daňovou evidenci.
    odometer_start       INT UNSIGNED NULL,
    odometer_start_date  DATE NULL,

    is_default           TINYINT(1) NOT NULL DEFAULT 0,     -- výchozí auto (default vazba když je jedno)
    is_archived          TINYINT(1) NOT NULL DEFAULT 0,
    note                 TEXT NULL,

    created_by           BIGINT UNSIGNED NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_cars_supplier (supplier_id),
    UNIQUE KEY uq_cars_supplier_reg (supplier_id, registration),

    CONSTRAINT fk_cars_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_cars_user     FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- Kategorie cest (číselník)
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS trip_categories (
    id                   SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id          TINYINT UNSIGNED NOT NULL,

    code                 VARCHAR(30)  NOT NULL,
    label                VARCHAR(100) NOT NULL,
    is_private           TINYINT(1) NOT NULL DEFAULT 0,     -- soukromá jízda (daňově neuznatelná)
    display_order        INT NOT NULL DEFAULT 0,
    is_archived          TINYINT(1) NOT NULL DEFAULT 0,

    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_tripcat_supplier (supplier_id),
    UNIQUE KEY uq_tripcat_supplier_code (supplier_id, code),

    CONSTRAINT fk_tripcat_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed výchozích kategorií per supplier (idempotentní přes NOT EXISTS).
INSERT INTO trip_categories (supplier_id, code, label, is_private, display_order)
SELECT s.id, 'business', 'Služební', 0, 10 FROM supplier s
 WHERE NOT EXISTS (SELECT 1 FROM trip_categories t WHERE t.supplier_id = s.id AND t.code = 'business');
INSERT INTO trip_categories (supplier_id, code, label, is_private, display_order)
SELECT s.id, 'private', 'Soukromá', 1, 20 FROM supplier s
 WHERE NOT EXISTS (SELECT 1 FROM trip_categories t WHERE t.supplier_id = s.id AND t.code = 'private');

-- ───────────────────────────────────────────────────────────────────────────
-- Jízdy
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS trips (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id          TINYINT UNSIGNED NOT NULL,
    car_id               INT UNSIGNED NOT NULL,

    trip_date            DATE NOT NULL,
    time_start           TIME NULL,
    time_end             TIME NULL,

    odometer_start       INT UNSIGNED NULL,
    odometer_end         INT UNSIGNED NULL,
    distance_km          DECIMAL(8,1) NOT NULL DEFAULT 0,   -- zadané nebo dopočtené (end - start)

    category_id          SMALLINT UNSIGNED NULL,
    purpose              VARCHAR(255) NULL,                 -- účel cesty
    origin               VARCHAR(255) NULL,                 -- odkud
    destination          VARCHAR(255) NULL,                 -- kam
    note                 TEXT NULL,

    created_by           BIGINT UNSIGNED NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_trips_supplier (supplier_id),
    KEY idx_trips_car      (car_id),
    KEY idx_trips_date     (supplier_id, trip_date),
    KEY idx_trips_category (category_id),

    CONSTRAINT fk_trips_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)        ON DELETE CASCADE,
    CONSTRAINT fk_trips_car      FOREIGN KEY (car_id)      REFERENCES cars(id)            ON DELETE CASCADE,
    CONSTRAINT fk_trips_category FOREIGN KEY (category_id) REFERENCES trip_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_trips_user     FOREIGN KEY (created_by)  REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- Tankování
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fuelings (
    id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id                TINYINT UNSIGNED NOT NULL,

    -- NULL = bez přiřazení auta (povolené jen když má tenant víc aut a nelze rozhodnout).
    car_id                     INT UNSIGNED NULL,

    fueled_date                DATE NOT NULL,
    fueled_time                TIME NULL,

    fuel_type                  VARCHAR(60) NULL,            -- popis paliva („Prémiová nafta", „Natural 95")
    quantity                   DECIMAL(10,3) NULL,          -- litry / jednotky
    unit                       VARCHAR(10) NOT NULL DEFAULT 'l',
    unit_price                 DECIMAL(12,4) NULL,          -- cena za jednotku
    amount_without_vat         DECIMAL(12,2) NULL,
    amount_vat                 DECIMAL(12,2) NULL,
    amount_with_vat            DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency                   CHAR(3) NOT NULL DEFAULT 'CZK',

    odometer                   INT UNSIGNED NULL,           -- stav tachometru při tankování (volitelně)
    station                    VARCHAR(150) NULL,           -- místo / síť („Město, Ulice / EuroOil")
    vendor_id                  BIGINT UNSIGNED NULL,        -- dodavatel (benzínka) z clients

    source                     ENUM('manual','invoice','axigon','axigon_ai','import') NOT NULL DEFAULT 'manual',
    source_purchase_invoice_id BIGINT UNSIGNED NULL,
    source_item_id             BIGINT UNSIGNED NULL,        -- purchase_invoice_items.id (audit, dedup)
    receipt_number             VARCHAR(40) NULL,            -- číslo účtenky (Axigon)
    raw_text                   VARCHAR(500) NULL,           -- fallback text, když nelze rozparsovat

    -- Dedup pro idempotentní (re)sken faktur — ruční záznamy mají NULL (nekolidují).
    dedup_hash                 VARCHAR(64) NULL,

    note                       TEXT NULL,
    created_by                 BIGINT UNSIGNED NULL,
    created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_fuelings_supplier (supplier_id),
    KEY idx_fuelings_car      (car_id),
    KEY idx_fuelings_date     (supplier_id, fueled_date),
    KEY idx_fuelings_invoice  (source_purchase_invoice_id),
    UNIQUE KEY uq_fuelings_dedup (supplier_id, dedup_hash),

    CONSTRAINT fk_fuelings_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_fuelings_car      FOREIGN KEY (car_id)      REFERENCES cars(id)     ON DELETE SET NULL,
    CONSTRAINT fk_fuelings_vendor   FOREIGN KEY (vendor_id)   REFERENCES clients(id)  ON DELETE SET NULL,
    CONSTRAINT fk_fuelings_invoice  FOREIGN KEY (source_purchase_invoice_id)
        REFERENCES purchase_invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_fuelings_user     FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- Marker vytěžených faktur — parse jen jednou (i pro zpětný backfill historie).
-- ───────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS logbook_fuel_scans (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id          TINYINT UNSIGNED NOT NULL,
    purchase_invoice_id  BIGINT UNSIGNED NOT NULL,

    parser               VARCHAR(40) NOT NULL,              -- axigon | axigon_ai | summary | …
    transactions_count   INT NOT NULL DEFAULT 0,
    status               ENUM('parsed','summary','failed') NOT NULL DEFAULT 'parsed',
    scanned_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_fuelscan_supplier (supplier_id),
    UNIQUE KEY uq_fuelscan_invoice (supplier_id, purchase_invoice_id),

    CONSTRAINT fk_fuelscan_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_fuelscan_invoice  FOREIGN KEY (purchase_invoice_id)
        REFERENCES purchase_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────────────────────────────────────────────────────────────────────
-- Příznak „benzínka" na dodavateli
-- ───────────────────────────────────────────────────────────────────────────
-- MariaDB podporuje ADD COLUMN/INDEX IF NOT EXISTS nativně → idempotentní bez PREPARE/EXECUTE.
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS is_fuel_station TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Dodavatel je benzínka (pro automatické rozpoznávání tankování)' AFTER is_vendor,
    ADD INDEX IF NOT EXISTS idx_clients_fuel_station (supplier_id, is_fuel_station);
