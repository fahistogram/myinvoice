# Výkaz materiálu vedle Výkazu práce → 2 položky na faktuře

## Kontext

Dnes má faktura jeden „Výkaz víceprací" (work report) s řádky práce (`description, work_date,
hours, rate`); do faktury se přenáší jako **jedna** souhrnná položka. Cílem je k tomu přidat
**Výkaz materiálu** — samostatný editor, který se do faktury přenese jako **druhá** souhrnná
položka. Materiál má místo hodin **množství + jednotku** (default „ks"), **bez data**, položky
zadávané **ve stylu editoru faktury** (cena **s i bez DPH** dle konvence dokladu). Obojí se
sumarizuje, takže každý výkaz nese **jednu sazbu DPH** (12 % / 21 %).

Rozhodnutí (potvrzeno s uživatelem):
- **Dva oddělené editory** na faktuře: **„Výkaz práce"** a **„Výkaz materiálu"** (2 místa,
  rozděleně). Materiálový editor = kopie pracovního, +/- upravená.
- Do faktury **2 souhrnné položky**: „Práce" (= title výkazu práce) + „Materiál" (default název
  **„Materiál"**, editovatelný).
- **Sazba DPH na úrovni výkazu** (jedna, protože sumarizujeme):
  - Výkaz práce: nově volitelná sazba **12/21 %** (dnes bere default faktury) — default **21 %**.
  - Výkaz materiálu: volitelná sazba, default **medium (12 %)**.
- Jednotka materiálu **volitelná z číselníku, default „ks"**.
- Cena materiálu se zadává/edituje **s i bez DPH** podle `prices_include_vat` dokladu (jako
  v editoru faktury) — `InvoiceMath` to už řeší, žádný zásah do daňového jádra.
- **Oddělená tabulka `work_report_materials`** (čistší než přetěžovat sloupec `hours`).

Daňová mechanika je hotová: `InvoiceMath::compute()` (`api/src/Service/Invoice/InvoiceMath.php`)
počítá DPH zdola i shora dle `prices_include_vat`. Souhrnný řádek nese svou `vat_rate_id` a
`InvoiceMath` dopočítá DPH.

## Datový model

Jedna `work_reports` řádka na fakturu (UNIQUE `invoice_id` zůstává) = nese **obě** části;
dva editory upravují každý svou část téže řádky.

### Migrace `db/migrations/0114_work_report_materials.sql` (`0113` zabírá payment-orders)
**Idempotentní** (MariaDB native `IF NOT EXISTS`):

```sql
ALTER TABLE work_reports
  ADD COLUMN IF NOT EXISTS vat_rate_id          INT UNSIGNED NULL  AFTER total_amount, -- sazba DPH práce (12/21); NULL = fallback default faktury
  ADD COLUMN IF NOT EXISTS material_title       VARCHAR(190) NULL  AFTER vat_rate_id,
  ADD COLUMN IF NOT EXISTS material_total       DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER material_title,
  ADD COLUMN IF NOT EXISTS material_vat_rate_id INT UNSIGNED NULL  AFTER material_total;
-- FK na vat_rates (vat_rate_id, material_vat_rate_id) přidat idempotentně (guard přes
-- information_schema, vzor v existujících migracích).

CREATE TABLE IF NOT EXISTS work_report_materials (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_report_id  BIGINT UNSIGNED NOT NULL,
  description     TEXT NOT NULL,
  quantity        DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit            VARCHAR(20) NOT NULL DEFAULT 'ks',
  unit_price      DECIMAL(12,2) NOT NULL DEFAULT 0,   -- v cenové konvenci dokladu (prices_include_vat)
  total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,   -- round(quantity * unit_price, 2)
  order_index     INT NOT NULL DEFAULT 0,
  KEY idx_wrm_wr (work_report_id, order_index),
  CONSTRAINT fk_wrm_wr FOREIGN KEY (work_report_id) REFERENCES work_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `total_hours`, `total_amount` = jen práce (význam nezměněn).
- `material_total` = součet `work_report_materials.total_amount`.

## Backend (`api/src/`)

Aby šly oba editory ukládat **nezávisle** (a neumazat si navzájem část), nejčistší jsou
**dva endpointy** nad sdílenou řádkou (vznik řádky `work_reports` lazy podle toho, který přijde dřív):

- **Routes** `Routes.php` (vedle stávajících work-report rout):
  - `PUT /api/invoices/{id}/work-report` — **práce** (existující, rozšířit o `vat_rate_id`).
  - `PUT /api/invoices/{id}/work-report/materials` — **materiál** (nový).
  - GET vrací obě části; DELETE materiálu volitelně přes prázdné `materials` v PUT.
- **`Repository/WorkReportRepository.php`**:
  - `save()` rozšířit o `?int $vatRateId` (uloží `work_reports.vat_rate_id`), jinak beze změny.
  - nové `saveMaterials(int $invoiceId, ?string $materialTitle, ?int $materialVatRateId, array $materials)`
    — upsert `work_reports` řádky (vznikne, když ještě není), DELETE+INSERT `work_report_materials`,
    spočítá a uloží `material_total`. Nesahá na práci/items.
  - `findByInvoice()` doplnit `vat_rate_id`, `material_title`, `material_total`,
    `material_vat_rate_id` a `materials[]` (nový private `materialsFor()`).
- **`Action/WorkReport/SaveWorkReportAction.php`** — přidat parse+validaci `vat_rate_id`
  (existuje ve `vat_rates`, 12/21).
- **`Action/WorkReport/SaveWorkReportMaterialsAction.php`** (nový, vzor dle SaveWorkReportAction) —
  draft-only guard, parse `material_title` (default „Materiál"), `material_vat_rate_id`,
  `materials[]`; validace: `description` neprázdný, `quantity > 0`, `unit` neprázdný,
  `unit_price >= 0`; když je aspoň 1 materiál → `material_vat_rate_id` povinné + existuje.
  Po uložení `pdf->invalidate(...)` + activity log.
- **DI/registrace** akcí (kontejner) + **PDF**: `templates/invoice/work_report.twig` +
  `Service/Pdf/WorkReportPdfRenderer.php` — pod tabulku práce vykreslit tabulku **„Materiál"**
  (popis, množství, MJ, cena/MJ, celkem) jen když `material_total > 0`.
- **Public tracking** `Service/WorkReport/WorkReportLinkService.php` (mapování ~ř. 332) —
  do preview přidat `materials[]` + `material_total` (+ sazby).

## Frontend (`web/src/`)

- **TS typy** `api/invoices.ts` (~ř. 590–617): do `WorkReport` přidat `vat_rate_id`,
  `material_title`, `material_total`, `material_vat_rate_id`, `materials: WorkReportMaterial[]`;
  nový `WorkReportMaterial { id?, description, quantity, unit, unit_price, total_amount?, order_index }`;
  `WorkReportPayload` rozšířit o `vat_rate_id`; nový `WorkReportMaterialsPayload`.
  API helpery: `saveWorkReportMaterials(invoiceId, payload, force?)`.
- **`components/modals/WorkReportModal.vue`** (práce) — přidat `<select>` **sazby DPH (12/21)**
  (`vat_rates` číselník, default 21 %); sazbu poslat v payloadu; v sync použít zvolenou sazbu
  pro řádek „Práce" místo `defaultVatRateId`.
- **`components/modals/MaterialReportModal.vue`** (nový, kopie WorkReportModal +/-):
  - položky ve stylu editoru faktury: **popis, množství (number), jednotka (`<select>` z `units`,
    default „ks"), cena/MJ**; bez data, bez hodin; živý přepočet `quantity × unit_price`.
  - titul výkazu (default „Materiál"), `<select>` **sazby DPH** (default **medium 12 %**).
  - popisek u cen „s DPH / bez DPH" podle `inv.prices_include_vat` (cena se ukládá v konvenci dokladu).
  - **Sync**: GET faktury → najít/aktualizovat/založit **řádek „Materiál"** (popis = `material_title`,
    qty=1, `unit_price_without_vat` = součet materiálu, `vat_rate_id` = zvolená sazba materiálu),
    ostatní řádky ponechat; když materiál prázdný → řádek odebrat. Matchovat proti **původnímu**
    `material_title` načtenému při otevření (drží rename v rámci modalu). Stejný pattern filtrace
    `item_kind='discount'` jako ve WorkReportModal.
- **`pages/invoices/InvoiceDetail.vue`** — vedle stávající sekce „Výkaz víceprací"/tlačítka přidat
  **druhé místo** „Výkaz materiálu" (vlastní tlačítko + náhledová tabulka, jen když `material_total > 0`).
  Importovat a instancovat `MaterialReportModal`. (Pozn.: WorkReportModal je instancován i v
  `InvoiceList.vue` ~ř. 777 — zvážit, zda tam přidávat i materiál; default ne, jen v detailu.)
- **Public tracking** `pages/WorkReportTrackingPublic.vue` (~ř. 239–304) + typy
  `api/workReportTracking.ts`: vykreslit i materiál.
- **i18n** `web/src/i18n/cs.json` + `web/src/i18n/en.json`: nové klíče `invoice.*` —
  `work_report_material` (titul modalu „Výkaz materiálu"), `wr_material_title` (default „Materiál"),
  `wr_material_qty`, `wr_material_unit`, `wr_material_unit_price`, `wr_vat_rate` (sdílené pro
  práci i materiál), `wr_material_add_item`, `wr_material_total`, `wr_material_saved_and_synced`.

## Co se NEmění
- `InvoiceMath` / daňové jádro — oba výkazy produkují běžné řádky faktury.
- Exporty ISDOC/Pohoda/Stereo — jedou z `invoice_items` (2 hotové řádky).
- OpenAPI `api/openapi.yaml` — work-report endpointy nejsou ve veřejném `/api/v1/*` subsetu.

## Tax / korektnost (povinné ověření před commitem)
- Smíšený doklad: práce 21 % + materiál 12 % → ověřit `vat_breakdown`, `totals` i `Knihu DPH`
  (sumace z `invoice_items`).
- Oba režimy `prices_include_vat` (zdola/shora): zadaná cena materiálu × množství = `total`,
  souhrnný řádek dá stejné DPH jako ruční položka se stejnou sazbou.

## Verifikace (end-to-end)
1. **Migrace**: `& c:\inetpub\php\php.exe api/bin/migrate.php` — spustit 2× (idempotence).
2. **Testy** (z `api/`): rozšířit `WorkReportRepository` test (uložení/načtení materiálu +
   `material_total` + `vat_rate_id`); nový integrační test (vzor
   `api/tests/Integration/Report/PaymentTaxDocumentVatTest.php`) pro práci 21 % + materiál 12 %
   v obou režimech `prices_include_vat` → 2 řádky faktury, správný `vat_breakdown`.
   Spuštění: `cd api; & c:\inetpub\php\php.exe vendor/bin/phpunit --testsuite Unit` (+ Integration).
3. **Frontend build**: `cd web; pnpm install` (jen při změně deps) → `npm run build`.
4. **Ruční E2E** na `dev.myinvoice.cz` (Edge + Claude extension): draft faktura → „Výkaz práce"
   (sazba 21 %) → „Výkaz materiálu" (MJ ks/kg, sazba 12 %, cena s i bez DPH) → uložit → ověřit
   2 položky na faktuře, správné DPH, PDF výkazu (2 tabulky), public tracking.
