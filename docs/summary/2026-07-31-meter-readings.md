# Session Summary — 2026-07-31 (Meter Readings)

## Goal
Implement the full Meter Readings phase: Filament manual entry form (auto-compute cu_m_used, auto-fill previous_reading), CSV bulk import (upload → preview → validate → import), and validation on import (per-row errors, flags suspicious readings, rejects invalid rows).

## Files Created

| # | File | Purpose |
|---|---|---|
| 1 | `backend/app/Filament/Resources/MeterReadingResource.php` | Resource: form, table, filters, global search, 5 pages |
| 2 | `.../Pages/ListMeterReadings.php` | List page + Create + Import CSV header actions |
| 3 | `.../Pages/CreateMeterReading.php` | Computes previous/cu_m_used, sets entered_by + method='manual' |
| 4 | `.../Pages/EditMeterReading.php` | Recomputes on save; protects entered_by/method |
| 5 | `.../Pages/ViewMeterReading.php` | Read-only detail + Edit action |
| 6 | `.../Pages/ImportMeterReadings.php` | Upload → preview → validate → import (Livewire) |
| 7 | `backend/app/Imports/MeterReadingImport.php` | Excel import (ToArray + WithHeadingRow) |
| 8 | `backend/app/Services/ReadingService.php` | All business logic (168 → 200 lines) |
| 9 | `backend/resources/views/filament/pages/import-meter-readings.blade.php` | Import page view (preview table, badges) |
| 10 | `backend/database/factories/MeterReadingFactory.php` | Factory with flagged() state |
| 11 | `backend/samples/meter-readings-sample.csv` | Sample CSV for manual testing |
| 12 | `backend/database/seeders/ServiceConnectionSeeder.php` | 15 connections (GW-00001..00015, MTR-xxxxx) |

## Files Modified
- `backend/database/seeders/DatabaseSeeder.php` — added ServiceConnectionSeeder call
- `backend/app/Models/ServiceConnection.php` — added `meterReadings()` HasMany
- `ARCHITECTURE.md` — Meter Readings checkboxes marked [x]

## Service Layer (ReadingService)
- `computeUsage()` — present − previous, rounded to 2 decimals
- `getLatestReading()` / `getPreviousReading()` — ordered by `entered_at DESC`
- `validateReading()` — connection exists, status active, present ≥ 0, date not future, no duplicate (same connection + date)
- `createFromArray()` — auto previous/cu_m_used, entered_by, method
- `prepareImportRows()` — per-row validation results, in-file duplicate detection, connection resolution
- `resolveConnection()` — matches account_number or meter_number regardless of status (fixed — see below)
- `validateHeaders()` — checks required columns (present_reading, account_number/meter_number)

## Bugs Found in Audit & Fixed (this session)

| # | Issue | Fix |
|---|---|---|
| 1 | Flagged rows rejected from CSV import (`valid = valid && !flagged`) | `valid = valid` — flagged rows import, stored with flagged=true (meter replacement case) |
| 2 | Unused ReadingService injection in MeterReadingImport | Removed constructor |
| 3 | No re-validation at import time | `DB::transaction()` + per-row try/catch, `$failed` counter, warning notification |
| 4 | Composite index (service_connection_id, entered_at) | SKIPPED — deferred to Billing phase (billing is what queries readings per connection) |
| 5 | Decimal fields not cast | Added `decimal:2` casts for present/previous/cu_m_used |
| 6 | Inactive connections never resolved in CSV (status filter in resolveConnection made validateReading's status check dead code) | Dropped status filter from resolveConnection; message → "No matching service connection found."; status errors now surface |
| 7 | In-file duplicates not caught (only DB duplicates) | `seenPairs` tracking (connection|date) → "Duplicate row within this file (row N)" |
| 8 | Header-less / wrong-column CSV silently produced garbage rows | `validateHeaders()` + danger notification with expected columns |
| 9 | Manual form used DateTimePicker (time forced) vs CSV date-only | Switched to DatePicker for consistency |

## Manual Test Results (user-tested 2026-07-31)
- A. Manual entry: #4 (previous auto-fill), #5 (cu_m_used live update), #6 (red on list) ✅; #7, #8 not fully tested
- B. CSV import: mostly works (tested one by one); flagged rows now import; empty/bad-header handling works
- C. List page: not yet fully tested
- D. Data integrity: not done (optional)

## Known Gaps → Next Session (see docs/prompts/meter-readings-roundtrip.md)
1. 30-day hard-block on reading dates (manual + CSV) — manual entry currently has NO date validation
2. Manual entry auto-flag when present < previous (currently manual toggle only)
3. CSV `flagged` column support (1/0, true/false, yes/no) + ignore extra columns
4. CSV round-trip: download preview with notes + flagged headers, fix offline, re-import
5. Import page UI polish (currently plain HTML — raw input, weak button styling)
6. Case-insensitive search (ilike) — dropdown at MeterReadingResource.php:54 (`like` fails on "gw" vs "GW"), table search, global search
7. Meter replacement dedicated marker — deferred, noted in ARCHITECTURE.md near end

## Git State at Session End
- Committed: `8b1c438` — `feat: meter readings phase complete + import robustness fixes, docs structure` (21 files, 1420 insertions). 14 commits ahead of origin/main.
- `temp.txt` at repo root left untracked (user scratch notes)

## Addendum — post-commit review pass (same day)
User review caught a missed promise: the meter-replacement note was claimed to be in
ARCHITECTURE.md but wasn't. Fixed in commit `f6b277f`:
- ARCHITECTURE.md Meter Readings section: meter replacement prose (negative cu_m_used is
  transient — chain self-corrects next reading)
- ARCHITECTURE.md Billing section: flagged readings must NEVER feed billing math (skip /
  treat as 0 / manual override) + composite index (service_connection_id, entered_at)
  noted for the Billing phase
- ARCHITECTURE.md new "Deferred" section near the end: meter replacement marker (labeling
  improvement, not functional gap)
- docs/insights/product-decisions.md §3 expanded with the swap-reading billing insight
- docs/prompts/meter-readings-roundtrip.md Phase 5 expanded (billing implication + index)

## Addendum 2 — runtime bugfix round (same day, live browser testing)

Found while clicking through `/admin/meter-readings` — none of these were in the audit;
all were Filament 5.7 API mismatches + one data-contract gap.

| # | Bug found | Root cause | Fix |
|---|---|---|---|
| 10 | `BadMethodCallException: TextColumn::defaultSort does not exist` | Filament 5.7: `defaultSort()` is **Table-level only** (`Table/Concerns/CanSortRecords.php`) | Removed from column; table's `->defaultSort('entered_at', 'desc')` already handled it |
| 11 | `Class Filament\Tables\Actions\ViewAction not found` | Filament 5.7 moved all actions to `Filament\Actions\*` (tables pkg only ships an enum) | `Tables\Actions\X` → `Actions\X`, dropped empty `bulkActions` |
| 12 | `SQLSTATE[22P02]: invalid input syntax for type bigint: "import"` on `/admin/meter-readings/import` | Route ordering — `/{record}` registered **before** `/import`, so "import" resolved as a record ID | Static routes before `{record}` in `getPages()` |
| 13 | `Undefined array key "entered_at"` in import preview | Early-exit branches of `prepareImportRows()` pushed raw CSV rows as `data` (no normalized keys) | Both branches now emit normalized `data` (`entered_at`, `present_reading`, `previous_reading`, `flagged`) + `connection` key; blade `?? null` defensive fix |

Lesson: Filament 5.7's API differs significantly from v3/v4 docs (Schema ≠ Form, action
namespaces, Table-level sorts). Treat any v3/v4 tutorial code as suspect until verified
against the installed vendor source.

## Addendum 3 — debugging + seeding round (same day)

**User question:** what is the "Service Connection*" field for, and what do I put in it?

- It is a searchable FK to `service_connections` (account number / meter number /
  registered name). It selects whose meter is being read and auto-fills previous_reading
  from that connection's latest reading.

**Root cause of the empty dropdown (this session's debugging):** live DB scan showed
`service_connections` = **0 rows** (meter_readings 0, users 2, barangays 15). The field
looked broken; it was a data-seeding gap, not a code bug.

**Fix:**
- Created `backend/database/seeders/ServiceConnectionSeeder.php` — seeds 15 connections
  via the existing factory (`GW-00001..15`, `MTR-00001..15`, random names/barangays)
- Registered in `DatabaseSeeder.php` (runs after BarangaySeeder)
- Ran targeted `php artisan db:seed --class=ServiceConnectionSeeder` → verified 15 rows
  via tinker. Factory's static counter means re-runs continue at `GW-00016` — no
  collision risk.

**Checklist items 9–18 audited against current code — all green:**
- 9/10 happy path + CSV Import badge, 11 "No matching service connection found.",
  12 status error now reachable (resolveConnection has no status filter),
  13 negative, 14 future date, 15 duplicate (DB + in-file seenPairs), 16 flagged-but-valid,
  17 empty/header-less (validateHeaders + warning), 18 "Imported N reading(s). M row(s)
  failed." — all present.

**CSV reading_date answer:** date-only (`2026-07-30`) is enough — Carbon parse defaults
to midnight; time is optional. Manual form now uses DatePicker (bug 9), so both entry
paths accept the same input.

**Git state at this point:** both docs modified but uncommitted (38 insertions);
`temp.txt` untracked at repo root.

## Next Step
Meter Readings gap fixes + CSV round-trip (prompt saved in `docs/prompts/meter-readings-roundtrip.md`), then Billing phase.

## Addendum 4 — round-trip session (same day, prompt meter-readings-roundtrip.md)

**Goal:** 30-day date hard block (manual + CSV), manual auto-flag, CSV `flagged` column, preview download with notes, import page UI polish, case-insensitive search, ARCHITECTURE.md sync. All 5 phases done in one session (user-approved).

**Files changed:**
- `backend/app/Services/ReadingService.php` — new `validateReadingDate()` (30-day + future, date-only boundary), `parseFlaggedValue()` (1/0/true/false/yes/no); `validateReading()` now delegates to `validateReadingDate()`; `prepareImportRows()` parses optional `flagged` column per row (before connection resolution, so intent survives invalid rows), ORs it with the auto-flag, and every result now carries `notes` (errors joined / meter-replacement message) + `original` (raw CSV row) for the round-trip download
- `backend/app/Filament/Resources/MeterReadingResource.php` — DatePicker `->rules()` closure calling `ReadingService::validateReadingDate()` (manual form; shared form = edit page also enforces); auto-flag `$set('flagged', true)` in the `afterStateUpdated` closures of connection Select + present/previous (toggle stays user-overridable); dropdown search `like` → `ilike` (item 8)
- `backend/app/Filament/Resources/MeterReadingResource/Pages/ImportMeterReadings.php` — real form schema (`FileUpload` cached via `cacheSchema('importForm', ...)`, root state path binds `$this->csvFile`), new `downloadCsv()` (streamDownload + fputcsv — no disk storage): all rows, original columns (original `flagged` column dropped) + `notes` + `flagged` 1/0
- `backend/resources/views/filament/pages/import-meter-readings.blade.php` — rebuilt with `x-filament::section`, `x-filament::badge` (Valid/Flagged/Invalid), `x-filament::button` for Import + Download; notes column now reads `$result['notes']`
- `ARCHITECTURE.md` — Meter Readings section: 30-day hard-block + CSV round-trip bullets; both Meter Readings checkbox descriptions updated (item 14). Meter-replacement/billing-guard/index notes untouched.

**Key research findings (verified in vendor source, Filament 5.7.3):**
- Items 9–10 needed NO code: Filament already wraps search in `lower()` on Postgres by default (`generate_search_column_expression`/`generate_search_term_expression` in `vendor/filament/support/src/helpers.php`, pgsql → case-insensitive true). Table + global search were already case-insensitive; only the custom dropdown closure (raw `like`) was broken. Documented so it isn't "fixed" later.
- Custom-page schema pattern: `BasePage` has `InteractsWithSchemas`; cache via `cacheSchema()` in `mount()`, render `{{ $this->importForm }}` (Schema is Htmlable). `makeSchema()` = `Schema::make($this)` → root state path → FileUpload binds to the plain Livewire property.

**Bugs found & fixed during testing:**
- 30-day boundary off-by-time: `strtotime('-30 days')` includes time-of-day, so a reading dated exactly 30 days ago was wrongly rejected. Fixed with date-only boundary (`date('Y-m-d', strtotime('-30 days'))`) — exactly 30 days = allowed, 31+ = rejected.

**Test results (tinker, not browser):**
- `validateReadingDate`: 2026-07-01 ok / 2026-06-30 rejected / future rejected / today ok / null ok ✅
- `parseFlaggedValue`: 1, true, YES → true; 0, no, '', null, false → false ✅
- `prepareImportRows` (5-row fixture with flagged/notes/foo junk columns): DB duplicate, in-file duplicate, 30-day error, no-connection — all correct notes + preserved CSV flagged intent; system auto-flag wins over csv `flagged=0` on a backward reading ✅
- `php artisan test` — 6/6 pass ✅
- **NOT yet browser-tested**: import page render (FileUpload schema), download CSV round-trip, manual form auto-flag/date rule UI, dropdown ilike. These need the user's manual pass (AGENTS.md: no test suite exists for this area).

**Git:** work + docs uncommitted as of this addendum.

## Addendum 5 — render verification + prod landmine (same day, before commit)

**Render tests (temp, deleted after passing):** a temporary `tests/Feature/TempImportPageRenderTest.php` (NOT committed) rendered both the Create page and the Import page in a test (needed `config()->set('app.env', 'local')` before `actingAs($user, 'admin')` — see landmine below). Create page passes; Import page returns 200 and renders the FileUpload schema wiring (`schemaKey: 'importForm'`, acceptedFileTypes, `$entangle('csvFile')`, `type="file"` input with `fi-fo-file-upload` class — note: NOT `fi-file-input`, which does not exist in Filament 5.7). `php artisan test` full run: 8/8 passed (6 existing + 2 temp). Temp test file deleted before committing.

**PROD LANDMINE (found, NOT fixed):** `vendor/filament/filament/src/Http/Middleware/Authenticate.php` aborts 403 unless the panel user implements `FilamentUser::canAccessPanel()` OR `config('app.env') === 'local'`. Our `app/Models/User.php` does NOT implement FilamentUser, so the `/admin` panel only works because dev env is `local` — in production every admin login would 403. **Fix (next session, before any prod deploy):** add `FilamentUser` contract + `canAccessPanel()` returning `$this->is_admin` (or similar) to the User model. Also the admin login form is Livewire-only (no plain CSRF token; `wire:submit="authenticate"`, `data.email`/`data.password`) — scripted HTTP login won't work; use `test-login`/browser.

**Final git state:** all work + this doc committed in one commit per plan (message `feat: meter readings 30-day rule, CSV round-trip, import UI polish, case-insensitive search`). Pre-existing dirty files not touched: `AGENTS.md` (modified), `temp.txt` (untracked). Dev server (PID 11572) stopped after verification.

## Addendum 6 — FilamentUser prod landmine FIXED (same day, commit de4ec0e)

**Fix applied** (per plan): `backend/app/Models/User.php` now `implements FilamentUser` with `canAccessPanel(Panel $panel): bool { return $this->is_admin; }` (is_admin already cast to boolean; single panel, no `$panel->getId()` branch needed). Auth is unchanged: `admin` guard → `admins` provider already filters `where is_admin = true`, so the contract is a defense-in-depth backstop — `/admin` now works in production.

**Regression test added (permanent):** `backend/tests/Feature/AdminPanelAccessTest.php` forces `config(['app.env' => 'production'])` in `setUp()` (so the local-env bypass can never mask a regression) and asserts: admin user → `/admin` 200; non-admin user → 403; guest → redirect to `/admin/login`. **9/9 tests pass** (6 existing + 3 new). Browser check can't prove this fix on dev (env=local works before and after) — the production-env test is the real proof.

**Commit:** `fix: implement FilamentUser so admin panel works in production` (de4ec0e). Landmine from Addendum 5 is resolved; no further action needed before prod deploy.

## Addendum 7 — import page file upload disappearing bug FIXED (same day, commit 8de72cd)

**Bug (user-reported):** CSV file upload button on `/admin/meter-readings/import` appeared for milliseconds, then vanished.

**Root cause (verified in `vendor/filament/schemas/src/Concerns/InteractsWithSchemas.php`):** `cachedSchemas` is a **protected** (non-persisted) property. `mount()` cached the real schema once, but on the next Livewire render (hydration/any update) the cache is empty, so `getSchema('importForm')` falls back to the reflection path (`cacheSchema($name)` with no args, lines 268–306), which calls `importForm(Schema $schema)` with a fresh **empty** schema. Our method returned it unchanged → empty schema → upload vanished. The blade `{{ $this->importForm }}` resolves via `__get()` → `getSchema()` (`ResolvesDynamicLivewireProperties.php:26`), so the empty schema was rendered.

**Fix:** moved the `FileUpload` components INTO `importForm(Schema $schema)` (returns the schema with components — the same pattern Filament's own `CreateRecord::form()` uses). `getImportForm()` now delegates to it. Every render path (cached or reflection fallback) now builds the full schema.

**Regression test (permanent):** `tests/Feature/ImportMeterReadingsPageTest.php` — `Livewire::test()` asserts `fi-fo-file-upload` present, then `->set('validCount', 1)` (forces a second Livewire request where `mount()` does not re-run), asserts present again. **Failed before the fix** (proving the root cause), passes after. Full suite 10/10.

**Commit:** `fix: keep import file upload visible across Livewire renders` (8de72cd).

## Addendum 8 — upload crash `[null]` property synthesizer FIXED (same day, commit d559536)

**Bug (user-reported):** after the file finishes uploading on the import page: `Exception … HandleComponents.php:773 "Property type not supported in Livewire for property: [null]"` on `/livewire-…/update`.

**Root cause (from the user's Laravel log stack trace + `vendor/livewire/livewire/src/Mechanisms/HandleComponents/HandleComponents.php`):** Filament's FileUpload uploads files under a **dotted name** = component state path + `.` + random UUID (`csvFile.cc419556-…`, seen in `_finishUpload` frame). Livewire's `updateProperty` treats dotted paths as deep sets: `recursivelySetValue()` (line 596–604) calls `propertySynth()` on the property's **current value** — `null` before the file lands — and no synthesizer matches `null`, so `getSynthesizerByTarget()` throws. Standard Filament forms nest fields under `data.csvFile`, so the deep-set drills into the `$data` array (ArraySynth) and never sees null; our page was the app's only root-level schema state path (`csvFile` directly on the component) — the anomaly. Latent bug exposed by Addendum 7's fix (upload now reachable).

**Fix (Option A — Filament-standard shape):** schema now `->statePath('data')` in `importForm()`; root property is `public array $data = []`; the upload lands in `$this->data['csvFile']` (uuid-keyed array — extraction helper `uploadedCsvFile()` is shape-agnostic, `collect()->first()`); auto-preview hook renamed `updatedCsvFile` → `updatedData` (Livewire derives the hook from the path's TOP segment — `SupportLifecycleHooks.php:67`); `import()` resets `$this->data = []`. Livewire 4.3.3.

**Tests:** new permanent `test_upload_completes_with_filament_dotted_path_and_auto_previews` in `tests/Feature/ImportMeterReadingsPageTest.php` — simulates the real browser flow (`_startUpload` → `FileUploadController::validateAndStore` → `_finishUpload` with `data.csvFile.<uuid>`) and asserts upload completes + auto-preview ran (`hasPreview`, `invalidCount=1`). **Failed before the fix with the exact production exception** (also required registering the `tmp-for-tests` disk in `setUp()` — Livewire's own test bootstrap normally does this). Full suite 11/11. Dev-server upload NOT yet browser-tested by me — needs user's manual pass.

**Commit:** `fix: nest import form state under data so Filament uploads don't crash Livewire` (d559536).

## Addendum 9 — manual test CSV committed (same day, commit 44e2a81)

`backend/samples/meter-readings-manual-test.csv` (14 rows) is a purpose-built fixture for the manual checklist — rows are deterministic against the seeded connections (GW-00004..00013 untouched at creation time; row 13 needs GW-00003's pre-existing DB reading). Verified end-to-end through the real pipeline (Excel parse → validateHeaders → prepareImportRows): 9 valid / 5 invalid.

**Row → test mapping (today 2026-07-31):**

| Row | Account / value | Expected | Confirms |
|---|---|---|---|
| 1 | GW-00004, 100.50, 07-30, flag=`1` | Valid, Flagged | D1 (`1`) |
| 2 | GW-00005, 75.00, 07-30, `true` | Valid, Flagged | D1 (`true`) |
| 3 | GW-00006, 50.00, 07-29, `yes` | Valid, Flagged | D1 (`yes`) |
| 4 | GW-00007, 40.00, 07-29, `0` | Valid, not flagged | D1 (`0`) |
| 5 | GW-00008, 30.00, 07-28, `no` | Valid, not flagged | D1 (`no`) |
| 6 | GW-00009, 20.00, 07-28, blank | Valid, not flagged | D1 (blank) |
| 7 | GW-00010, 10.00, **07-01** | Valid — exactly 30 days = allowed | boundary |
| 8 | GW-00011, 60.00, **06-30** | Invalid — more than 30 days old | B4 (30-day) |
| 9 | GW-00012, 70.00, **08-01** | Invalid — future | B4 (future) |
| 10 | GW-99999 | Invalid — no matching connection | regression |
| 11 | GW-00013, **-5.00** | Invalid — negative | regression |
| 12 | GW-00004, 101.00, **07-30** | Invalid — duplicate within file (row 1) | regression |
| 13 | GW-00003, 30.00, 07-29, `0` | **Valid + Flagged** (previous 73 > 30; auto-flag beats CSV `0`) | D2 |
| 14 | GW-00005, 25.00, 07-31, `false` | Valid, not flagged | D1 (`false`) |

**Two-pass flow:** Pass 1 — upload → check badges → Import (9 rows). Pass 2 — re-upload same file → rows 1–7/13/14 show "already exists on this date" (DB-duplicate detection + round-trip). Note: on any fresh DB where GW-00004..13 have no prior readings, rows 1–3's "lower than previous" note text is the generic flagged note (ReadingService.php:210–212) — flagged there comes from the CSV column.

Also: `backend/.gitignore` gained `/storage/framework/livewire-tmp/` and `/samples/meter-readings-preview-*.csv` (user keeps a downloaded preview artifact locally for now — not deleted). The current `meter-readings-preview-20260731-101200.csv` stays untracked+ignored.

**Commit:** `chore: commit manual-test CSV and ignore preview artifacts` (44e2a81).

## Addendum 10 — 30-day rule corrected to gap rule + honest flag notes (same day, pre-commit)

**Goal:** fix the 30-day rule that was misunderstood + the misleading "lower than previous" note, per the user's manual-test findings.

**The misunderstanding:** the rule was implemented as "reject dates older than 30 days from today". The user's actual intent: **a new reading is only allowed when its date is at least 30 days after the connection's last reading** (monthly billing cycle — you can't bill the same account twice within one month). First readings are exempt (no age limit — backdating a first reading stays legal). Exactly 30 days = allowed.

**The note bug:** `prepareImportRows()` merged `validation['flagged'] || csvFlagged` BEFORE picking the note text, so any row flagged only via the CSV column got the lying note "Present reading is lower than previous" — proven by the user's test: rows 2–8 and 15 flagged via CSV with previous 0.00 and present HIGHER, yet the note claimed the opposite. Only GW-00003 (30 vs previous 73) was genuine.

**Changes (`backend/app/Services/ReadingService.php`):**
- `validateReadingDate(?string $date, ?string $previousReadingDate = null)` — removed the absolute `-30 days from today` check; added gap check: `date < previousDate + 30 days` → error "Reading date must be at least 30 days after the previous reading ({date})." Future-date check unchanged.
- `validateReading()` — passes the connection's latest reading date (`getLatestReading()->entered_at->toDateString()`) into `validateReadingDate()`.
- `prepareImportRows()` — tracks `$autoFlag` (present < previous) separately from `$csvFlagged`; notes = errors ? errors : autoFlag ? meter-replacement text : csvFlagged ? "Flagged via CSV." : ""; `flagged` still `autoFlag || $csvFlagged` (CSV can never suppress the auto-flag).

**Manual form (`MeterReadingResource.php`):** DatePicker rule closure now reads `$get('service_connection_id')` and passes that connection's latest reading date into `validateReadingDate()` — manual entry enforces the same gap + future rules as CSV.

**Tests:** new `backend/tests/Feature/ReadingServiceTest.php` (7 tests, `RefreshDatabase` on the `gw_system_testing` DB): future rejected; 20-day gap rejected with the new message; exactly 30 days allowed; first reading no age limit; CSV-only flag → "Flagged via CSV."; auto-flag beats CSV `0` with meter-replacement note; import preview invalidates a <30-day-gap row. Full suite **18/18 pass**.

**Docs:** ARCHITECTURE.md bullet (line 71) + both Meter Readings checkboxes rewritten to the gap rule; `docs/insights/product-decisions.md` §4 gained a dated correction (gap rule + flag-note honesty); `docs/prompts/meter-readings-roundtrip.md` context + item 1 rewritten so no future session re-implements the old rule.

**Samples:** deleted stale `samples/meter-readings-preview-20260731-101200.csv` (internally inconsistent artifact of an intermediate build); added `samples/meter-readings-gap-test-run1.csv` + `run2.csv` (two-run test: run 1 imports GW-00030 100.00 on 07-01; run 2 uploads 120.00 on 07-20 → gap error + 130.00 on 07-31 → valid, exactly 30 days). Note: `meter-readings-manual-test.csv` rows 7–8 (GW-00010 07-01 / GW-00011 06-30) are no longer age-rejected on a fresh DB — they're now valid first readings; on a re-upload GW-00005's 25.00 on 07-31 (+1 day after its 75.00 on 07-30) now triggers the gap error.

**Known behavior (documented, not a bug):** the gap check compares against the DB's latest reading only — rows inside the same CSV file don't affect each other's gap check on a first upload (on a second upload, DB state catches them).

**NOT browser-tested (needs user's manual pass):** the manual-form gap rule UI, honest notes in the preview table + downloaded CSV, gap-test CSV two-run flow.

## Addendum 11 — flag levels (0/1/2) instead of boolean (same day, pre-commit)

**Goal:** the user asked — if a row is flagged via CSV but there's nothing wrong with it, can the system tell? Yes: `flagged` became **flag levels** so a flag's *source* is visible and a level-1 flag gets an explicit "no automatic basis" note.

**Semantics:** `0` = not flagged; `1` = flagged by CSV column or manual override (no automatic basis detected); `2` = auto-flagged because `present < previous` (meter replacement). Any non-zero = suspicious for the Billing guard (unchanged).

**Changes:**
- **Migration** `2026_07_31_000011_change_flagged_to_smallint_on_meter_readings_table.php` — `ALTER TABLE meter_readings ALTER COLUMN flagged TYPE smallint USING flagged::int` (had to `DROP DEFAULT` first — Postgres can't auto-cast a column with a default; first run failed, fixed). Existing rows: false→0, true→1.
- **Model** `MeterReading.php` — cast `'flagged' => 'integer'`.
- **`ReadingService::prepareImportRows()`** — `$flagLevel = $autoFlag ? 2 : ($csvFlagged ? 1 : 0)`; notes: level 2 → "Present reading is lower than previous (meter may have been replaced)"; level 1 → "Flagged via CSV - no automatic issue detected"; no-connection/in-file-duplicate branches set csvFlagged as level 1. Auto-detection fires even with no `flagged` column in the file (user's key question — yes, it does).
- **Manual form** (`MeterReadingResource.php`) — Toggle → Select (Not flagged / Flagged / Meter replacement (present < previous)); auto-sets 2 when present < previous (3 places), user can override. Removed unused `Toggle` import.
- **Table** — IconColumn → badge: `—` gray / `Flagged` warning / `Meter replacement` danger; filter TernaryFilter → SelectFilter (0/1/2).
- **Import page** — preview badge per level (warning "Flagged" / danger "Meter replacement"); download CSV writes the real level `0/1/2` instead of boolean 1/0.

**Tests:** `ReadingServiceTest` — CSV-only flag asserts level 1 + "Flagged via CSV - no automatic issue detected"; auto-flag asserts level 2 + meter-replacement note; new `test_no_flagged_column_still_auto_detects_low_reading` (no `flagged` column at all → still level 2). Full suite **19/19 pass**.

**Round-trip stability:** downloaded level-2 rows re-derive level 2 on re-import (present < previous persists in data); level-1 rows re-import as 1. A CSV value of `2` is NOT parsed as a flag (reserved for auto).

**Docs:** ARCHITECTURE.md meter-replacement bullet + CSV round-trip bullet + checkbox 175 updated to levels; product-decisions.md §4 gained "Third correction"; prompt file item 3 rewritten.

**NOT browser-tested (needs user's manual pass):** Select control on manual form, badge/filter in table, preview badges, downloaded CSV values.

## Addendum 12 — insert-time recompute kills the stale-preview quirk (same day, pre-commit)

**Goal:** fix the row-14 quirk found during manual-test planning — a row later in the same CSV file imported with the preview's stale `previous_reading`/flag (GW-00005's 25.00 imported after 75.00 in the same file would land **unflagged with cu_m_used = −50**, a billing hazard: negative usage that isn't flagged).

**Fix (`ReadingService::createFromArray()`):** at insert, fetch the actual latest reading for the connection and recompute — `$previous = latest ? latest->present_reading : (data previous ?? 0)`; `$flagged = present < previous ? 2 : (int)(data['flagged'] ?? 0)`. The auto-detect is ground truth at insert for **all** paths (CSV in-file order, manual double-entry races, any stale preview). Stored previous is always the true one.

**Semantic consequence (documented in the Select helper text):** manual override to 0/1 is ineffective when present < previous — level 2 always wins.

**Manual form:** Flagged Select gained helper text: "A present reading lower than previous always saves as Meter replacement (level 2)."

**Tests (+3, suite 22/22):** in-file-order recompute (stored previous 75.00, cu −50.00, flagged 2); level 1 preserved when present not lower; level 0 kept when present not lower.

**Docs:** ARCHITECTURE meter-replacement bullet (insert-time recompute sentence); product-decisions.md §4 "Fourth correction" (incl. preview-is-a-snapshot rationale).

**Deliberately out of scope:** preview simulating in-file order (contradicts the documented DB-only gap check); pre-existing hole where manual create doesn't check date duplicates (form only validates the date window) — separate fix if ever needed.
