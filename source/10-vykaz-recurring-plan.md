# Plán: Otevřený koncept — opakovaná fakturace s průběžným výkazem víceprací

> **Datum:** 2026-05-26
> **Cíl:** umožnit fakturační model „fixní SLA + nepravidelné vícepráce" jako opakovanou fakturaci, kde koncept faktury vzniká na začátku období, vícepráce se do něj zapisují průběžně celý měsíc, a na konci měsíce se faktura automaticky vystaví s fixní částkou + výkazem.
> **Status:** ✅ implementováno 2026-05-26 (migrace 0051, generator openDraft/issuePeriod, cron 3 fáze, reminder e-mail, UI draft_open_mode, 12 integračních testů). Rozhodnutí 1–3 viz níže.

---

## Problém

Uživatel má pravidelnou fakturaci, kde:
- **část je fixní** (paušál / SLA, stejný každý měsíc),
- **část je proměnná** (vícepráce — nepravidelný seznam, vzniká průběžně během měsíce).

Dnes to řeší ručně: vytvoří koncept, na něm edituje **výkaz práce** (editovatelný jen v konceptu), a vystaví. Chybí:
1. **Průběžnost** — kam zapisovat vícepráce *během* měsíce, ne až na konci.
2. **Automatizace** — fixní část + kalendář by měla řešit opakovaná fakturace.

## Současný stav (zjištěno v kódu)

Dva systémy, které se dnes **vůbec nepotkávají**:

### Výkaz práce
- `work_reports` (1:1 k faktuře) + `work_report_items` — `db/migrations/0001_init.sql:292-337`
- Editovatelný **jen v draftu** — `api/src/Action/WorkReport/SaveWorkReportAction.php:46-48` (admin force `?force=1`)
- `WorkReportModal.vue` po uložení synchronizuje **jednu** položku faktury („Vícepráce") s totály výkazu
- Renderuje se do PDF faktury — `api/templates/invoice/work_report.twig`, integrace `invoice.twig:394-448`

### Opakovaná fakturace
- `recurring_invoice_templates` + `recurring_invoice_template_items` — `db/migrations/0021_recurring_invoices.sql`
- **Statické** řádky šablony, žádná per-instance customizace (jediná dynamika: `increment_month_in_descriptions` přepíše M/YYYY v textu)
- `tax_date_mode`: `same_as_issue` | `previous_month_last_day` — `db/migrations/0025_recurring_tax_date_mode.sql`
- Generátor `RecurringInvoiceGenerator::generate()` v **jednom kroku**: vytvoří draft → přepočítá → volitelně `auto_issue` + `auto_send_email` → posune `next_run_date`
- Cron `api/bin/cron-generate-recurring-invoices.php` — denně, catch-up den po dni

### Klíčová mezera
Generátor klonuje **statické** řádky a (při `auto_issue`) vystaví hned. Není kam během období přidávat proměnné položky. Výkaz práce by to uměl, ale koncept v dnešním modelu vzniká až v okamžiku vystavení → není ho kam psát dřív.

---

## Rozhodnutí (odsouhlaseno)

1. **Pojistka proti předčasnému auto-vystavení = jen reminder** den předem. Žádný per-koncept zámek.
2. **DUZP = poslední den měsíce**, určeno `tax_date_mode` šablony, **přepočítané při vystavení**.
3. **Recurring auto-issue je samostatný issue krok** (přepočítá datumy dle šablony). **Approval flow zůstává beze změny** (viz níže).

### Kontext rozhodnutí 3 — proč ne přes `AutoIssueAndSendService`

`AutoIssueAndSendService::run()` (po schválení výkazu klientem) pouze alokuje VS + snapshoty a překlopí `draft → issued → sent`. **Nepřepočítává `issue_date` ani `tax_date`** — VS se dokonce generuje z původního `issue_date` (`AutoIssueAndSendService.php:175-177`). Pro náš model „otevři 1.5., vystav 31.5." by to dalo `issue_date = 1.5.` (špatně). Proto recurring potřebuje **vlastní** issue krok, který datumy přepočítá. Approval flow necháváme tak, jak je — jeho „datum = vznik konceptu" chování u krátkého request→approve okna nevadí.

---

## Návrh: „Otevřený koncept"

Klíč = **rozpojit vznik konceptu od vystavení**.

```
1.5.       cron OTEVŘE koncept květnové faktury
            • naplní fixní SLA řádky ze šablony
            • status = draft, BEZ VS (přiřadí se až při vystavení)
            • notifikace „koncept otevřen, dopisuj vícepráce"

5.–31.5.   uživatel průběžně edituje VÝKAZ PRÁCE na konceptu
            • dnešní editor (funguje jen v draftu ✓)
            • vícepráce se rolují do jedné položky vedle fixních SLA řádků

30.5.      reminder „zítra se vystaví, máš N víceprací" (rozhodnutí 1)

31.5.      cron VYSTAVÍ koncept (auto_issue)
            • PŘEPOČÍTÁ issue_date + tax_date dle tax_date_mode (rozhodnutí 2,3)
            • alokuje VS + snapshoty, přepočítá totály vč. výkazu
            • status = issued, volitelně sent
```

Model výsledné faktury = **fixní SLA řádky + jedna položka „Vícepráce"** (detail = výkaz). Přesně odpovídá uživatelovu mentálnímu modelu a maximálně recykluje existující výkaz práce.

---

## Datový model

Migrace **idempotentní** (MariaDB native `IF NOT EXISTS`), spouštět jen přes `php api/bin/migrate.php`. Číslo dle aktuální sekvence (ověřit nejvyšší existující, pokračovat dál — orientačně `00XX_recurring_open_draft.sql`).

`recurring_invoice_templates` — přidat:

| sloupec | typ | význam |
|---|---|---|
| `draft_open_mode` | ENUM(`at_issue`,`period_start`) DEFAULT `at_issue` | `at_issue` = dnešní chování (vznik=vystavení); `period_start` = otevři koncept na začátku fakturovaného období |

- **Zpětná kompatibilita:** default `at_issue` → existující šablony se chovají beze změny.
- Pro měsíční SLA s `end_of_month=1` je „period start" triviálně 1. den měsíce. Pro `day_of_month` jiné než konec měsíce je definice období méně jednoznačná — v první verzi **podporovat `period_start` jen pro `frequency=monthly`** (validace ve formuláři) a období = kalendářní měsíc, jehož posledním dnem je issue datum.
- **Žádné nové tabulky** — vícepráce žijí v existujícím `work_reports` na otevřeném konceptu (rozhodnutí z diskuse: vazba na koncept nevadí).

Volitelně (zvážit): `reminder_days_before` TINYINT DEFAULT 1 — kolik dní před vystavením poslat reminder. Pro v1 lze natvrdo 1 den.

---

## Backend

### Rozdělení generátoru
`RecurringInvoiceGenerator::generate()` rozdělit na dvě odpovědnosti:

- **`openDraft(template)`** — vytvoří draft s fixními řádky, `status=draft`, **bez VS/snapshotů**, bez vystavení. Nastaví na faktuře cílové issue/tax datum (plánované), aby UI ukázalo, kdy se vystaví.
- **`issueDraft(invoiceId, template)`** — **přepočítá** `issue_date` + `tax_date` dle `tax_date_mode`, alokuje VS + snapshoty, přepočítá totály (vč. položky výkazu), `status=issued`, volitelně `auto_send`. Vlastní logika, **ne** `AutoIssueAndSendService`.

Pro `draft_open_mode=at_issue` zůstává tok jako dnes (open+issue v jednom běhu) — sdílet kód, jen nevkládat mezikrok.

### Cron — dva průchody
`cron-generate-recurring-invoices.php` rozšířit:

1. **OPEN fáze** — šablony s `draft_open_mode=period_start`, kde dozrál draft-open datum (1. den fakturovaného měsíce) a **ještě neexistuje** koncept pro dané období → `openDraft()`. Idempotence: kontrola existence draftu přes `recurring_template_id` + období (issue měsíc).
2. **ISSUE fáze** — recurring-linked **koncepty** (`recurring_template_id` not null, `status=draft`), kterým dozrál issue datum a `auto_issue=1` → `issueDraft()`. Pokud byl koncept mezitím vystaven ručně, není už draft → fáze ho přirozeně přeskočí.
3. **REMINDER fáze** — koncepty, kterým je `reminder_days_before` dní do vystavení a reminder ještě neodešel → notifikace. Evidovat odeslání (sloupec/flag nebo activity log lookup), ať se neposílá opakovaně.

Pozor na **catch-up** logiku (dnes den-po-dni posun `next_run_date`) — při dvou datech (open vs. issue) ověřit posun rozvrhu, ať se období nepřeskočí ani nezdvojí.

### Edge-cases
- **DUZP přepočet musí být ve fázi ISSUE**, ne při OPEN — jinak by `tax_date` odpovídala vzniku konceptu (přesně chyba, kterou má rozhodnutí 2/3 řešit).
- **Manuální vystavení během měsíce** — koncept přestane být draft, ISSUE fáze ho přeskočí. OK.
- **Žádné vícepráce** — koncept se vystaví jen s fixními SLA řádky. OK.
- **Více klientů/projektů** — každý má vlastní šablonu a vlastní otevřený koncept; více konceptů „čeká".

---

## Frontend (Vue)

- `RecurringForm.vue` — přidat přepínač **„Kdy vytvořit koncept"** (`draft_open_mode`): *Až při vystavení* (default) / *Na začátku období (pro průběžný výkaz)*. Zobrazit jen relevantně (period_start vyžaduje `frequency=monthly`). Vysvětlující hint.
- `RecurringDetail.vue` — ukázat aktuálně **otevřený koncept** s odkazem na editaci výkazu.
- **Dashboard** — sekce „Otevřené koncepty k doplnění" (recurring drafty čekající na vícepráce + datum vystavení). Volitelné, ale výrazně zlepší UX průběžnosti.
- Výkaz práce sám = **beze změny**, editor už na konceptu funguje.
- i18n: nové klíče v `cs.json` + `en.json` od začátku (per `feedback_i18n`). Po změnách `npm run build` (per `feedback_build_after_ts`).

---

## Notifikace (reminder)

- Den před vystavením (`reminder_days_before`, default 1) e-mail dodavateli: „Koncept faktury {VS-placeholder/název} pro {klient} se zítra vystaví. Aktuálně {N} víceprací, celkem {částka}. Doplnit: {odkaz}."
- Použít existující mailer + šablonový mechanismus. Nový e-mail template klíč (CS/EN).
- Idempotence odeslání: flag na faktuře nebo lookup do activity logu, aby se reminder neposlal vícekrát.

---

## Co se NEdělá / mimo rozsah

- Žádná změna approval flow (`AutoIssueAndSendService`, `PublicApprovalDecideAction`).
- Žádná samostatná evidence víceprací mimo fakturu (varianta C zamítnuta — vazba na koncept nevadí).
- Žádný per-koncept „zámek proti vystavení" (zvolen jen reminder).
- `period_start` zatím jen pro `frequency=monthly`.

---

## Otevřené body k rozhodnutí při implementaci

1. **Definice „období"** pro `next_run_date` posun při dvou datech — promyslet schedule advancement, ať OPEN i ISSUE sedí na stejné období.
2. **Reminder evidence** — nový sloupec `reminder_sent_at` vs. activity-log lookup.
3. **`reminder_days_before`** — konfigurovatelné per šablona, nebo v1 natvrdo 1 den.
4. **Verze** — minor bump; zařadit do release dle `feedback_release_workflow` (VERSION → CHANGELOG → manuál → commit → tag → GHCR + bundle přes CI).
5. **Manuál** — nová/rozšířená kapitola u pravidelných fakturací (vsuvka s podpísmenkem dle `project_manual_renumber`).
