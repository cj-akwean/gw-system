# Meter Readings Decisions & Rules — Catalog for Verification

Everything decided for the **Meter Readings phase** (checklist items 14–15:
`App\Services\ReadingService` + `MeterReadingResource`), written as
**Question → Decision → Status → Code ref** so each decision can be verified —
either against the running system, or against the **Guinobatan Waterworks office**
when the real-world process needs confirming.

**Status legend:**
- **Confirmed** — you (project owner) decided this; it's implemented and tested.
- **Assumption (verify with office)** — seeded/implemented value or behavior that should
  be checked against the real waterworks practice before it becomes "true".
- **Deferred** — explicitly postponed; not implemented.

**Verified** tag on Part 1 items: `suite` = covered by an automated test; `browser` =
manually verified in the admin UI; `browser-pending` = implemented + suite-tested but
the UI interaction still needs your manual pass.

Part 2 carries a **Type** column so you can filter before going to the office:
`Office` = real-world process question (ask them); `System` = internal data-entry
decision (filter out when asking — it's your process, not theirs); `Both` = affects both.

---

## Part 1 — Confirmed decisions (implemented + tested)

### 1. What does GW-System replace in the meter-reading process?
**Question:** Does the system automate the actual meter reading (smart meters, hardware)?
**Decision:** No. The physical walk stays — a person walks the barangay and reads each
meter by hand. GW-System replaces what happens **after** the walk: data entry,
validation, billing, collections.
**Status:** Confirmed.
**Code ref:** none (positioning) — see product-decisions.md §5.
**Office-verify note:** The entire readings design assumes staff walk meters monthly —
confirm who actually reads and how they're organized (see Part 2, Q1–Q3).

### 2. How do readings get into the system?
**Question:** One entry path or two?
**Decision:** Two, both in Filament admin: **CSV bulk import** (one file per reading
day/route, `method = 'csv_import'`) and **manual form** (`method = 'manual'`) for
individual corrections. Every reading records who entered it (`entered_by`), when
(`entered_at`), and how (`method`) — full audit trail.
**Status:** Confirmed (`suite` + `browser`).
**Code ref:** `MeterReadingResource::form()` hidden fields (`entered_by`, `method`);
`ReadingService::createFromArray(..., $method)`.

### 3. What does each reading store?
**Question:** Which fields does a reading row hold?
**Decision:** `present_reading`, `previous_reading`, computed `cu_m_used` (decimal 10,2
each), `entered_by`, `entered_at`, `method`, `flagged`. The three decimal fields map
1:1 to the columns printed on real PH water bills (present / previous / cu.m. used),
so admin views look like what customers already recognize.
**Status:** Confirmed.
**Code ref:** migration `2026_07_30_000005_create_meter_readings_table`; `MeterReading`
casts (`decimal:2`, `entered_at` datetime, `flagged` integer).
**Office-verify note:** System stores 2 decimal places — meters are often whole-number
or 1-decimal. Ask what the office actually reads (Part 2, Q9).

### 4. How are previous and usage computed?
**Question:** Where does `previous_reading` come from, and how is `cu_m_used` derived?
**Decision:** `previous_reading` = the connection's **latest** reading by `entered_at`
(or 0 for the first reading); `cu_m_used` = `round(present − previous, 2)`.
**Status:** Confirmed (`suite`).
**Code ref:** `ReadingService::getLatestReading()`, `getPreviousReading()`,
`computeUsage()`.

### 5. Is the preview's previous/flag trustworthy at import time?
**Question:** A row later in the same CSV file can change what "previous" means before
the file finishes importing. Does the stored row use the preview's values?
**Decision:** **No — recomputed at insert time.** `createFromArray()` fetches the
actual latest reading at insert and recomputes `previous_reading`, `cu_m_used`, and the
auto-flag. The preview is a validation snapshot only; the DB is truth. This is what
kills the stale-preview hazard (unflagged negative usage from in-file ordering).
**Status:** Confirmed (`suite` — in-file-order regression test).
**Code ref:** `ReadingService::createFromArray()`; product-decisions.md §4 "Fourth
correction".

### 6. What happens when `present < previous` (meter replacement)?
**Question:** The new meter starts at 0, so a valid reading can be lower than last
month's. Reject it?
**Decision:** **Never rejected — auto-flagged level 2** and stored. The chain
self-corrects on the next reading (its previous = the new meter's value). A CSV
`flagged` value can never suppress the auto-detect; the manual form's flag Select is
also overridden (helper text: "A present reading lower than previous always saves as
Meter replacement (level 2)"). Billing must skip flagged readings (see
billing-decisions.md Part 1 §2).
**Status:** Confirmed (`suite` + `browser`).
**Code ref:** `createFromArray()` `$flagged = $present < $previous ? 2 : ...`;
`MeterReadingResource` flag Select + helper text; product-decisions.md §1–§3.
**Office-verify note:** Ask what else besides replacement makes a reading lower (tampering,
wrong digit) and what the office does about it (Part 2, Q11).

### 7. What do flag levels 0/1/2 mean?
**Question:** A flag should say *why* it exists, not just that it does.
**Decision:** `0` = not flagged; `1` = flagged by CSV column or manual override — no
automatic basis detected (notes say exactly that; the system can't prove tampering
reasons invisible in the data); `2` = auto-flagged because `present < previous` (meter
replacement). Any non-zero level = "suspicious" for the Billing guard. A CSV `flagged`
value only ever sets level 1 — level 2 is system-reserved; a CSV value of `2` is NOT
parsed as a flag.
**Status:** Confirmed (`suite`).
**Code ref:** migration `2026_07_31_000011_change_flagged_to_smallint_on_meter_readings_table`;
`parseFlaggedValue()`; `prepareImportRows()` `$flagLevel = $autoFlag ? 2 : ($csvFlagged ? 1 : 0)`.

### 8. What is the minimum gap between readings?
**Question:** Two readings of one meter within one month can't be billed twice — when
is a reading date allowed?
**Decision:** A new reading is accepted only when its date is **at least 30 days after
the connection's last reading** (monthly-billing cycle). Exactly 30 days = allowed;
sooner = hard-blocked. **First readings are exempt** (no age limit — backdating a first
reading stays legal). Future dates are rejected outright. The gap is checked against
the DB's latest reading only — rows inside the same CSV file don't affect each other's
gap check on a first upload.
**Status:** Confirmed (`suite` + `browser` — boundary, first-reading, future-date,
in-file-non-interference tests).
**Code ref:** `ReadingService::validateReadingDate()`; form DatePicker rule closure.
**Office-verify note:** Real-world cadence may not be a clean 30-day cycle — see Part 2,
Q2, Q13, Q14.

### 9. Can two readings exist for the same connection on the same date?
**Question:** A duplicate reading for one meter on one day is always an entry error —
how is it prevented?
**Decision:** One reading per connection per date, enforced **three ways**: the manual
form rule (Create and Edit), the import path (DB check + in-file duplicate tracking),
and a **Postgres unique expression index** on `(service_connection_id,
entered_at::date)` that backstops every path including insert races and future API
writes.
**Status:** Confirmed (`suite` + `browser`).
**Code ref:** `validateReadingDuplicate()` (with `$ignoreReadingId` for edits); migration
`2026_07_31_000012_add_unique_connection_date_index_to_meter_readings_table`.

### 10. What CSV columns are required / allowed?
**Question:** What shape must an import file have?
**Decision:** Required: `present_reading` + (`account_number` **or** `meter_number`).
Optional: `reading_date` (defaults to today), `flagged` (see §14). Headers are matched
case-insensitively; **any other columns are silently ignored**.
**Status:** Confirmed (`suite`).
**Code ref:** `ReadingService::validateHeaders()`; upload section description text on the
import page.

### 11. How is a CSV row matched to a connection?
**Question:** Which identifier resolves a row to a `ServiceConnection`?
**Decision:** `account_number` first, then `meter_number` — matched against any
connection **regardless of status** (so a status error surfaces as a validation error
instead of silently skipping). No match → invalid row ("No matching service connection
found.").
**Status:** Confirmed (`suite` + `browser`).
**Code ref:** `ReadingService::resolveConnection()`.

### 12. What makes a CSV row invalid vs flagged-but-valid?
**Question:** Which problems reject a row outright, vs import-with-flag?
**Decision:** **Invalid (row skipped):** missing connection, negative present reading,
connection not active, future date, <30-day gap, duplicate date (DB or in-file).
**Valid (imports):** anything where `present < previous` — stored flagged level 2. The
line: unambiguously wrong → rejected; suspicious-but-plausible → imported + flagged.
**Status:** Confirmed (`suite` + `browser`).
**Code ref:** `ReadingService::validateReading()`; product-decisions.md §1.

### 13. What happens to invalid rows during import?
**Question:** One bad row in a 200-row file — does the whole import fail?
**Decision:** No. Preview shows valid/invalid counts with per-row badges and notes;
**only valid rows are imported**, inside a single `DB::transaction()` with per-row
try/catch and a failure counter ("Imported N reading(s). M row(s) failed."). Invalid
rows are skipped, not silently dropped — the notes column names the reason for each.
**Status:** Confirmed (`suite` + `browser`).
**Code ref:** `ImportMeterReadings::preview()`, `import()`; blade preview table.

### 14. How is the CSV `flagged` column parsed?
**Question:** What values does an optional `flagged` column accept?
**Decision:** `1/0`, `true/false`, `yes/no`, `y` — case-insensitive; blank/null = not
flagged; sets **level 1** only. Anything else is treated as not flagged (including a
value of `2` — reserved for auto-detect).
**Status:** Confirmed (`suite`).
**Code ref:** `ReadingService::parseFlaggedValue()`.

### 15. What does the CSV round-trip look like?
**Question:** The office fixes invalid rows offline — how?
**Decision:** After preview, the full preview (valid **and** invalid rows) downloads as
CSV — the original columns (original `flagged` column dropped) plus `notes` (per-row
errors / flag message) and `flagged` (`0/1/2`). Fix offline, re-upload, re-preview;
already-imported rows are caught by the DB duplicate check and never imported twice.
Download is streamed in-memory (no disk storage).
**Status:** Confirmed (`suite`; download UI `browser-pending`).
**Code ref:** `ImportMeterReadings::downloadCsv()`; `prepareImportRows()` `notes`/`original`.

### 16. What file types/sizes can be uploaded?
**Question:** Limits on the import file?
**Decision:** CSV mime types only (`text/csv`, `text/plain`, `application/vnd.ms-excel`),
max 2 MB. Note: `.xlsx` is **blocked by the file input** even though Maatwebsite/Excel
could read it — CSV-only is intentional for the office's Excel handoff (export as CSV).
**Status:** Confirmed (`browser`).
**Code ref:** `ImportMeterReadings::importForm()` `acceptedFileTypes()` + `maxSize(2048)`.

### 17. What does the manual entry form enforce?
**Question:** A clerk corrects one reading by hand — what rules apply?
**Decision:** Connection required (searchable by account/meter/registered name);
`present_reading`/`previous_reading` required numeric (previous auto-fills from the
latest reading; cu_m_used auto-computes live); reading date defaults to today and
enforces the **30-day gap + duplicate-date** rules; flag Select (0/1/2, auto-sets 2 on
`present < previous`); `entered_by` = current admin, `method = 'manual'` (hidden).
**Status:** Confirmed (`browser`).
**Code ref:** `MeterReadingResource::form()`.

### 18. Does the manual form check connection status / negative present?
**Question:** Manual entry skips full `validateReading()` — is that a hole?
**Decision:** **Known asymmetry — yes, it's a gap.** The manual form enforces only the
date rules (gap + duplicate). It does NOT reject a negative `present_reading` (no
`min:0` rule) and does NOT check the connection is active — a reading for a
disconnected account can be saved manually. The CSV path validates both. Deliberately
documented here rather than silently fixed (see Part 3).
**Status:** Confirmed (documented gap).
**Code ref:** `MeterReadingResource::form()` DatePicker rule closure (only
`validateReadingDate` + `validateReadingDuplicate`); compare `validateReading()` (CSV).

### 19. Can a reading be edited after entry?
**Question:** What can the Edit page change?
**Decision:** Present/previous/flag/date are editable. On save, `previous_reading` and
`cu_m_used` are **recomputed** (not trusted from the form), and `entered_by`/`method`
are protected (`unset` — the audit trail can't be rewritten). A date edit re-runs the
gap + duplicate checks, with the duplicate check ignoring the record's own id (editing
a reading's date in place stays legal).
**Status:** Confirmed (`suite` for dup-ignore-own-id; edit page UI `browser-pending`).
**Code ref:** `EditMeterReading::mutateFormDataBeforeSave()`; form DatePicker rule
closure (`$record?->id`).
**Office-verify note:** No guard exists against editing a reading **that was already
billed** (see Part 3, gap G3).

### 20. How is the readings table browsed?
**Question:** What does the list page offer?
**Decision:** Columns: #, account, meter, registered name, barangay, present/previous/
cu.m. (negative cu_m_used shown in red), method badge (manual/csv_import), flag badge
(— / Flagged / Meter replacement), entered by, reading date. Search on account/meter/
registered name (case-insensitive by Postgres default — no code needed), filters for
method, flag level, barangay, date range; default sort `entered_at desc`; global search
across account/meter/registered name.
**Status:** Confirmed (`browser`; barangay filter + global search `browser-pending`).
**Code ref:** `MeterReadingResource::table()`; `getGloballySearchableAttributes()`.

### 21. Is the import atomic?
**Question:** A mid-import failure — partial rows or nothing?
**Decision:** Import runs inside one `DB::transaction()` with per-row try/catch — a
failing row counts as failed but doesn't roll back its siblings; a hard failure rolls
back everything. Combined with the unique index (§9), nothing can be imported twice.
**Status:** Confirmed (`suite` — page-level upload/import tests).
**Code ref:** `ImportMeterReadings::import()`.

### 22. What happens to readings when a connection is deleted?
**Question:** Deleting a `ServiceConnection` leaves orphan readings?
**Decision:** Readings cascade-delete with their connection
(`cascadeOnDelete`). Note for the Billing phase: a billed reading referenced by an
invoice would break on delete — no guard yet (see Part 3, G3).
**Status:** Confirmed (schema behavior).
**Code ref:** migration `2026_07_30_000005` `foreignId('service_connection_id')->constrained()->cascadeOnDelete()`.

### 23. What is tested?
**Question:** Which behaviors have automated coverage?
**Decision:** 25 tests: `ReadingServiceTest` (future/gap/boundary/first-reading rules,
CSV flag levels + honest notes, auto-flag precedence, insert-time recompute, duplicate
detection incl. edit), `ImportMeterReadingsPageTest` (page render, upload round-trip,
dotted-path Livewire regression), `AdminPanelAccessTest` (panel access, not reading-
specific). Browser pass of the import flow + gap tests was done by you; round-trip
download, flag Select, badges, filters and global search still need your manual pass.
**Status:** Confirmed (as of 2026-07-31 commits; see summary `docs/summary/2026-07-31-meter-readings.md`).

---

## Part 2 — Assumptions to verify with the Guinobatan Waterworks office

Implemented behaviors or seeded values, each tied to a question. **Type** tells you
which to actually ask the office (`Office`/`Both`) versus which are internal system
decisions you can filter out (`System`). Where the behavior is "System", the question
column still notes what the office answer would *change* if it ever differed.

| # | Type | Assumption in the system | Question for the office / what the answer would change | Where to look |
|---|---|---|---|---|
| Q1 | Office | A reader physically walks and reads each meter by hand; no hardware (product-decisions §5) | Who actually reads the meters — your staff walking? How are they organized — by barangay, route, or alphabetical list? | ARCHITECTURE.md Meter Readings |
| Q2 | Office | Monthly cycle — one reading per connection per month; gap rule = ≥30 days | How often are meters read, and is it a fixed day every month? | `validateReadingDate()` |
| Q3 | Office | `entered_at` = the actual walk/read day | Is a reading recorded on the day it's physically read, or on a fixed cut-off date (e.g. last day of month)? | `entered_at` field |
| Q4 | Office | Readings arrive as CSV (or are typed manually) after the walk | How do you record readings today — paper logbook, Excel, or software? Could you export a sample file so the CSV shape matches what you can produce? | Import page |
| Q5 | Office | `entered_by` = the person who typed the reading in | Does the reader enter readings themselves, or does a clerk re-type from paper/notes? | `entered_by` audit field |
| Q6 | Office | Accounts are identified by account number + meter number; system seeded invented `GW-XXXXX` account numbers | What identifier appears on your bills and records — account number, meter number, or both? What format? (CSV resolution + customer linking depend on this.) | `ServiceConnectionSeeder`, `resolveConnection()` |
| Q7 | System | `registered_name` = original applicant, fixed even when tenants change | (Renter-change handling) — does the record keep the original applicant's name? System does; ARCHITECTURE says this mirrors real practice. | ServiceConnection model |
| Q8 | Office | One connection = one meter (schema is 1:1) | Can a single account have multiple meters (e.g. compound)? Would one bill cover several meters? | ServiceConnection schema |
| Q9 | Office | Readings stored to 2 decimals; meters assumed readable to decimals | Do you read meters to whole numbers, 1 decimal, or 2? Do you estimate decimals on analog meters? | `decimal(10,2)` casts |
| Q10 | Office | Meter replacement → new meter at 0, recorded as a normal reading; auto-flag level 2 | When a meter is swapped, do you record the new 0 reading immediately? Do you keep a replacement log? Does the old meter's last reading get carried anywhere? | flag level 2 flow |
| Q11 | Office | Any `present < previous` is treated as suspect (level 2), investigated later | Besides meter replacement, when else does a reading come out lower than last month (tampering, wrong digit, misread)? What do you do then? | flag levels 0/1/2 |
| Q12 | Office | Zero-usage readings are stored and billing **skips** them ("verify meter locked/closed, or bill manually") | What do you do for vacant properties or locked/closed meters — still read and bill ₱0, or skip? Do you physically lock/close meters for long-vacation accounts? | billing-decisions.md A9; billing skip |
| Q13 | Office | A missed meter in a cycle → no reading → billing skips + reports; **system never estimates** | If a meter is missed one cycle, do you estimate the bill, bill the minimum, or let it carry over to next month? | billing-decisions.md A5/A9 |
| Q14 | Office | Any gap ≥30 days is allowed — a catch-up reading can legally cover 2 months | If a meter is missed a month, does the next reading cover 2 months' usage in one bill? Is that normal practice? | gap rule |
| Q15 | Office | Unreadable meters aren't specially handled — reading just doesn't exist for that day | A meter that can't be read (damaged, dog, locked gate) — what do you do? Estimate, skip, or revisit? | no unreadable-marker concept |
| Q16 | Office | Disconnect/reconnect happens offline (status changes only); no final/opening reading event | When you disconnect or reconnect a service, do you take a final or opening reading? | ServiceConnection status |
| Q17 | Office | Flagged readings → investigated + billed manually by the office (billing guard skips them) | Who re-checks a suspicious reading, and how soon after the walk? Same day or before billing? | billing-decisions.md §2 |
| Q18 | Office | No approval/re-verify step between data entry and billing (`billing:run` bills directly) | Is there a verification/approval step before bills go out — someone double-checks the day's readings? | BillingService |
| Q19 | Office | New connections pay their first month over the counter, then join the online cycle (billing skips no-reading connections) | For a brand-new connection, when does the first reading happen and how is the first bill handled? | billing-decisions.md §3 |
| Q20 | System | Reading date must be at least 30 days after the previous reading; first readings exempt | (Cadence assumption — Q2) — if real cadence is e.g. every 45 days, the gap rule still works; if faster than monthly, it would block valid reads. | gap rule |
| Q21 | System | CSV column contract: `account_number`/`meter_number` + `present_reading`, optional `reading_date`/`flagged`; extra columns ignored | (Handoff format — Q4) — if the office exports from software with different column names, the importer needs the file adapted to these headers. | `validateHeaders()` |
| Q22 | System | CSV only at upload (`.xlsx` blocked); max 2 MB | (Handoff format — Q4) — Excel files must be saved as CSV before upload. | FileUpload `acceptedFileTypes` |
| Q23 | System | Manual entry enforces date rules only (no status / negative-present checks — CSV path is stricter) | (Internal entry workflow — filter when asking) — manual edits are the clerk's correction path; status/negative checks live in CSV validation. | Part 1 §18 |
| Q24 | System | Preview is a validation snapshot; stored values are recomputed at insert (DB is truth) | (Internal workflow — filter when asking) — in-file ordering can't cause unflagged negative usage. | Part 1 §5 |

### Quick printable checklist (bring to the office — Office/Both rows only)
1. Who reads the meters, and how are they organized (barangay/route)?
2. How often is each meter read — fixed day every month?
3. Is a reading recorded on the walk day or on a fixed cut-off date?
4. How are readings recorded today (paper/Excel/software)? Can I see a sample?
5. Does the reader enter readings, or does a clerk re-type them?
6. Account identifier on bills — account number, meter number, or both? Format?
7. Can one account have multiple meters / one bill cover several meters?
8. Do you read to whole numbers, 1 decimal, or 2?
9. Meter replacement: when/how decided? New 0 recorded immediately? Replacement log kept?
10. Besides replacement, when does a reading come out lower than last month, and what do you do?
11. Vacant / locked-meter accounts — still read and bill ₱0, or skip?
12. Missed meter in a cycle — estimate, minimum charge, or carry over?
13. Does a catch-up reading cover 2 months in one bill — normal?
14. Unreadable meter (damaged/dog/gate) — estimate, skip, or revisit?
15. Disconnect/reconnect — is a final/opening reading taken?
16. Who re-checks suspicious readings, and when?
17. Is there an approval/verification step before bills go out?
18. New connection — first reading timing and first-bill handling?

---

## Part 3 — Deferred / known gaps

- **Dedicated "meter replaced" marker** — the flag workflow already functions; a
  distinct marker/note is a labeling improvement, not a functional gap. (ARCHITECTURE.md
  Deferred; product-decisions.md §3.)
- **G1 — Manual entry validation asymmetry:** the manual form enforces only date rules;
  a negative `present_reading` and non-active connections are rejected on CSV import but
  NOT on the manual form. Fix candidates: `min:0` rule + status check via
  `validateReading()` in the form.
- **G2 — Estimated readings:** no concept in the schema or services. Whether it's ever
  needed depends on the office's answer to Q13/Q15 (missed or unreadable meters).
- **G3 — Editing/deleting readings after billing:** the Edit page freely rewrites
  `present_reading`/date of any reading, including one already covered by an invoice
  (the Billing phase's unique constraint references `meter_reading_id`; a changed or
  deleted billed reading silently desyncs the historical bill). No guard exists. The
  Billing phase's already-billed guard is keyed on `meter_reading_id` only — a
  rewritten reading keeps its id, so a re-bill would use the new values. Deferred;
  flagged for the Admin Panel phase.
- **G4 — No approval/re-verify step before billing:** `billing:run` bills whatever is
  in the table; flagged rows are the only built-in pause. Whether the office needs a
  formal "sign off the day's readings" step is an open office question (Q18).
- **G5 — Missed-month catch-up (>30 days) is allowed by design:** documented behavior,
  not a bug (product-decisions.md §4). Revisit if the office says two-month gaps
  shouldn't exist.
- **G6 — `.xlsx` blocked at upload:** intentional CSV-only handoff; if the office can
  only produce Excel, either add the xlsx mime type or ask for CSV export (Q22).
- **G7 — Browser-test debt:** round-trip CSV download, flag Select, badges/filters,
  barangay filter and global search are suite-tested but still need your manual pass
  (Part 1 §15/§19/§20 statuses).

---

*Maintained as part of the Meter Readings phase (checklist items 14–15). Companion docs:
`docs/insights/product-decisions.md` (§1–§5 hold the original "why" narrative),
`docs/insights/billing-decisions.md` (what billing does with flagged / no-reading /
zero-usage rows), `docs/summary/2026-07-31-meter-readings.md`, and ARCHITECTURE.md
"Meter Readings" section.*
