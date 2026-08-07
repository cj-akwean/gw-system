# 2026-08-07 — Applicant fields on service_connections + `pending` status

## Goal
Complete the ARCHITECTURE.md item "Applicant fields on `service_connections`" under Customer
Registration (Admin): add `phone`, `email`, `gender`, `birthdate`, `civil_status`, `occupation`
(all nullable), extend `status` with `pending`, and update CRM edit form + factory. User chose
**constrained selects** for gender (male/female) and civil_status (single/married/widowed/separated).

## Files modified
| File | What |
|---|---|
| `backend/database/migrations/2026_08_07_000001_add_applicant_fields_to_service_connections_table.php` (N) | 6 nullable columns after `address`: phone string(20), email string(255), gender string(20), birthdate date, civil_status string(20), occupation string(100); down() drops them |
| `backend/app/Models/ServiceConnection.php` (M) | `#[Fillable]` gains the 6 fields; `birthdate => 'date'` cast added |
| `backend/app/Filament/Resources/ServiceConnectionResource.php` (M) | Form: phone (tel, max 20), email (email rule, max 255), gender Select (male/female), birthdate DatePicker `maxDate(today())`, civil_status Select (single/married/widowed/separated), occupation (max 100). Status Select + table filter gain `'pending' => 'Pending'`; badge color `pending => 'info'` |
| `backend/database/factories/ServiceConnectionFactory.php` (M) | PH-format phone (`09## ### ####`), safeEmail, random gender/civil_status, birthdate 18–70 yrs ago, jobTitle occupation |
| `backend/app/Exports/ServiceConnectionsExport.php` (M) | 6 new headings + map cells (null-safe `?? ''`), inserted after `address`/before `status` |
| `backend/tests/Feature/ServiceConnectionResourceTest.php` (M) | Edit test now fills all applicant fields + asserts DB row; +`test_edit_can_set_pending_status_without_required_applicant_fields`, +`test_pending_status_renders_in_list_badge_and_filter` |
| `backend/tests/Feature/ServiceConnectionsExportTest.php` (M) | Header updated; pin applicant values; null-safe row asserts empty cells; shifted indexes |
| `backend/tests/Unit/Exports/ServiceConnectionsExportTest.php` (M) | Header + full-row fixture + shifted map indexes (7→13, 8→14, 9→15, 6→12, 5→11, 7→13) |
| `backend/tests/Feature/BillingServiceTest.php` (M) | +`test_run_skips_pending_connections` |
| `backend/tests/Feature/ReadingServiceTest.php` (M) | +`test_validate_reading_rejects_non_active_connections` (pending) |
| `backend/tests/Feature/DashboardMetricsServiceTest.php` (M) | +`test_connections_count_excludes_pending` |

## Bugs found & fixed
- N/A (greenfield change). One test-time fix: the export feature test's second connection row
  unexpectedly exported factory-populated applicant values — pinned them to explicit NULLs so the
  null-safe export path is actually exercised (blank cells), not masked by defaults.
- Removed `fake()->unique()->safeEmail()` from the factory: `unique()` tracking persists across the
  whole PHPUnit process, risking a "Unable to generate unique value" exception on long runs. No DB
  unique constraint on email exists (deliberate) so uniqueness isn't needed.

## Key decisions
- `gender` / `civil_status` are **constrained selects** (user directive, 2026-08-07), not free text.
- No `->unique()` on the email form field — multiple connections may share one email (product-decisions §26).
- `pending` needs NO service-side guard changes: billing (`where('status','active')`), reading
  (`status !== 'active'`), and dashboard active-count all already exclude it. Verified by new tests.

## Test results
- Targeted: 96/96 green (ServiceConnection*, Reading, Billing, Dashboard) at first pass.
- **Full suite: 369/369 passed, 1316 assertions** at first pass; **375/375, 1338 assertions** after audit fixes (`php -d memory_limit=512M vendor/phpunit/phpunit/phpunit`).
- `./vendor/bin/pint --dirty` / pint on changed files (cosmetic); `php -l` clean.

## Audit fixes applied (senior-BE review of commit 11dc8a2)
- **Server-side enum validation**: `gender` (`in:male,female`) and `civil_status`
  (`in:single,married,widowed,separated`) gained `->rules()` in the Filament form — before, the Select
  only constrained the UI, so direct writes (tinker/seeders/future API) could persist arbitrary values.
  `phone` gained a `regex:/^[0-9+\-() ]+$/` rule.
- **Portal link gating**: `ConnectionLinkController::store()` now requires `where('status','active')` —
  previously a customer could self-link a `pending`/`inactive`/`disconnected` connection by
  account+meter number. Aligns with the existing `index()` `where('status','active')` guard.
- **Factory breadth**: `civil_status` now rotates through all 4 accepted values (was single/married only)
  so widowed/separated enum paths get exercised.
- **6 new tests**: invalid gender rejected, invalid civil_status rejected, pending→active promotion,
  active linkable via `/api/links`, pending + disconnected not linkable (404, no row).

## Edge cases checked (audit second pass)
- Existing rows keep working — all new columns nullable, no backfill.
- Null applicant values export as empty string, not `0`/`null` leak — asserted.
- `pending` excluded from billing, readings, and dashboard customer count — asserted.
- Birthdate future dates blocked at the form via `maxDate(today())`.
- CSV formula-injection sanitize applies to the new free-text cells (same helper).

## Known gaps / next step
- Manual verify: edit a connection in `/admin`, save applicant fields (invalid gender/civil_status must
  fail), link a `pending` account via the portal (must 404), view page + export CSV.
- Migration already applied to dev DB (`php artisan migrate`).
- Next unchecked item: **Create-new-connection flow in CRM** (create page + account/meter issuance).
- Not yet run: `graphify . --update` (model gained fillable columns; small — run next structural session).