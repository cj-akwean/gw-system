# Prompt: Meter Readings — Round-Trip, Data Rules, Search, UI Polish

> Copy-paste this whole file into the next session. Read `docs/summary/2026-07-31-meter-readings.md`
> and `docs/insights/product-decisions.md` first for context. Do phases in order;
> one phase per session if needed (AGENTS.md rule).

## Context

GW-System (Laravel 13 + Filament 5 admin, Postgres dev = prod). Meter Readings phase is DONE.

CSV import at `/admin/meter-readings/import`: upload (headers: `account_number` and/or
`meter_number`, `present_reading`, `reading_date`) → preview table with per-row
valid/invalid + notes → "Import N Valid Reading(s)" imports valid rows.

Current rules in `app/Services/ReadingService.php` (`validateReading()`):
- Reject: `present_reading < 0`, future `reading_date`, reading date less than 30 days after the connection's last reading (monthly cycle; exactly 30 = allowed), DB duplicate (same connection + date — also enforced by a DB unique index and the manual form), in-file duplicate
- Flag (level 2): present < previous (meter replacement case — see docs/insights/product-decisions.md §1)
- `resolveConnection()` matches by account_number or meter_number regardless of status; status errors come from `validateReading()`
- `validateHeaders()` rejects files missing `present_reading` or both `account_number`/`meter_number`

Manual entry (`/admin/meter-readings/create`) enforces the same date rules as CSV (future + 30-day gap since the connection's last reading, duplicate-date rejection on Create and Edit) and auto-flags present < previous as level 2.

Key files:
- `backend/app/Services/ReadingService.php`
- `backend/app/Imports/MeterReadingImport.php`
- `backend/app/Filament/Resources/MeterReadingResource.php`
- `backend/app/Filament/Resources/MeterReadingResource/Pages/ImportMeterReadings.php`
- `backend/resources/views/filament/pages/import-meter-readings.blade.php`
- `backend/app/Models/MeterReading.php`

## Phase 1 — Data entry rules (manual + CSV both)

1. **30-day gap since last reading (HARD BLOCK)**: reject any reading whose date is less than 30 days after the connection's last reading (monthly billing cycle; exactly 30 days = allowed; first readings are exempt — no age limit), and keep rejecting future dates. Applies to manual form AND CSV import. Per-row error: "Reading date must be at least 30 days after the previous reading ({date})." This replaces the earlier "older than 30 days from today" rule — see docs/insights/product-decisions.md §4 correction.
2. **Manual entry auto-flag**: on manual create, if present < previous, auto-set flagged to level 2 (Meter replacement); the 3-option Select (0/1/2) still allows overriding to 0/1, but level 2 always wins at insert when present < previous.
3. **CSV flagged column**: importer reads an optional `flagged` column — accept 1/0, true/false, yes/no, empty = not flagged. Valid rows with flagged=true import with flag level 1 (see below). All other extra columns are IGNORED. Flag levels: 0 = not flagged, 1 = flagged by CSV/manual (no automatic basis), 2 = auto-flagged because present < previous (meter replacement; system-reserved, fires even with no `flagged` column in the file).

## Phase 2 — CSV round-trip with notes

4. **Download preview as CSV**: button after preview exports ALL rows (valid AND invalid) with original columns + `notes` (per-row errors/flag text) + `flagged` (0/1/2).
5. **Tolerant import**: unknown columns (notes, flagged, anything) silently ignored — no errors for them.
6. **Re-import fixed files**: user fixes rows offline, re-imports; fixed rows import normally (invalid rows were never imported, so no duplicates). Rows already imported in a previous run correctly show as DB duplicates.

## Phase 3 — Import page UI polish

7. Rebuild import page with proper Filament components: FileUpload for the file input, styled preview table with proper badges, real button styling for "Import N Valid Reading(s)" and "Download CSV with notes". Keep the upload → preview → import flow intact.

## Phase 4 — Case-insensitive search (Postgres ilike)

8. `MeterReadingResource.php` `getSearchResultsUsing()` (Service Connection dropdown, ~line 54): change `like` → `ilike` for `account_number`, `meter_number`, `registered_name`. Currently typing "gw" finds nothing but "GW" works (Postgres `like` is case-sensitive).
9. Meter Readings table `searchable()` columns (`serviceConnection.account_number`, `.meter_number`, `.registered_name`): make case-insensitive with query callbacks (`ilike`).
10. Global search (`getGloballySearchableAttributes`): same treatment.
11. Verify multi-column search still ORs correctly after the change (searching a term should match any of the three fields, case-insensitively).

## Phase 5 — ARCHITECTURE.md updates

12. In ARCHITECTURE.md Meter Readings section: document the 30-day hard-block rule (monthly cycle rationale). A meter-replacement note + Billing guard already exist there (added 2026-07-31) — don't duplicate.
13. The "Meter replacement marker" deferred note and the "flagged readings must not feed billing math" Billing-section requirement are ALREADY in ARCHITECTURE.md (added 2026-07-31). Keep them, do not remove. The composite index note (service_connection_id, entered_at) is also already in the Billing section.
14. If any checklist item's behavior changed (e.g. validation rules), note it in the Meter Readings checkbox descriptions.

## Billing phase heads-up (do NOT start it this session)

The Billing phase must implement: flagged readings never feed billing math (skip / treat
as 0 / manual override before billing) and the composite index on
meter_readings (service_connection_id, entered_at). Both are documented in
ARCHITECTURE.md Billing section.

## Constraints

- Business logic stays in ReadingService; keep the existing upload → preview → import flow
- No permanent file storage
- Postgres only (`ilike` is fine — dev = prod = Postgres)
- Follow existing code style (no comments unless needed); `php -l` on changed files
- Update `docs/summary/` with a short addendum describing what this session changed
