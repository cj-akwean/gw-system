# 2026-08-06 — Payments CSV Export (Admin Reports / Exports item 1)

## Goal
Implement the Payments CSV export checklist item: `App\Exports\PaymentsExport`
(maatwebsite/excel, CSV only), "Export CSV" header action on `PaymentResource` that
respects the table's active filters (method, invoice status, paid_at range).

## Files created / modified
| File | What |
|---|---|
| `backend/app/Exports/PaymentsExport.php` (new) | `FromQuery + WithHeadings + WithMapping`. Constructor takes the table's already-filtered Eloquent builder (`getTableQueryForExport()` from the page), clones it, eager-loads `invoice.serviceConnection` + `recordedBy`, orders `paid_at` desc. 11 columns: paid_at, invoice_no, account_no, meter_no, customer_name, amount, method, reference, payer_name, payer_email, recorded_by. Reuses `PaymentResource::methodLabel()`/`processedByLabel()`; `sanitize()` prefixes `= + - @` cells with `'` (CSV formula-injection guard for payer-supplied text). |
| `backend/app/Filament/Resources/PaymentResource.php` (M) | `methodLabel()`, `channelLabel()`, `processedByLabel()` made `public static` (was `private static`) so the export reuses the exact table labels. Also fixed the `invoice.status` SelectFilter (see bug #1). |
| `backend/app/Filament/Resources/PaymentResource/Pages/ListPayments.php` (M) | "Export CSV" header action (`heroicon-o-arrow-down-tray`) → `Excel::download(new PaymentsExport($this->getTableQueryForExport()), 'payments-<Ymd-His>.csv')`; `deleteFileAfterSend(true)` so no file persists. |
| `backend/tests/Feature/PaymentsExportTest.php` (new) | 8 tests: unfiltered export (headers + all rows, sorted, full row content incl. `PayMongo · GCash` label, payer identity, `—` recorded-by for cash), method filter, invoice-status filter, paid_at range filter, combined filters, headers-only on empty result, formula-injection escaping, recorded-by name. |
| `backend/tests/Unit/Exports/PaymentsExportTest.php` (new) | 5 tests on `headings()`, `map()` row shape, missing serviceConnection → empty cells, webhook payment recorded-by = "PayMongo", injection escaping. |
| `ARCHITECTURE.md` | Payments CSV export item checked. |

## Bugs found & fixed (root cause, not symptom)
1. **Pre-existing: `invoice.status` filter generated broken SQL.** `SelectFilter::make('invoice.status')`
   with no `->query()` produced `where "invoice"."status" = ?` — no join → Postgres
   `SQLSTATE[42P01] missing FROM-clause entry for table "invoice"`. The filter was broken in the
   table UI too (any use would 500), but the export made it a hard blocker since the export must
   respect that filter. Fixed with an explicit `->query()` closure using
   `whereHas('invoice', fn ($iq) => $iq->where('status', $data['value']))`. Verified with a
   throwaway probe (real Postgres): before = 42P01, after = valid `where exists` subquery.
   **Trap hit:** `when(filled($data['value']), fn($q, $status) => ...)` passes the *boolean* result
   of `filled()` as `$status` (Laravel `when()` semantics), producing `where status = true`
   (binding `1`). First fix attempt failed the feature test; replaced with an explicit
   `if (blank(...)) return $query;` guard and direct `$data['value']` use.
2. **CSV amounts lose trailing zeros.** PhpSpreadsheet's ValueBinder casts numeric strings to
   numbers at cell-write time, so `750.50` is emitted as `750.5`. Numbers remain exact (no
   precision loss) and SUM cleanly in Excel — accepted as intended behavior; `map()` still
   formats `number_format(..., 2)` (asserted at unit level), tests assert the CSV's normalized
   form.
3. **`map()` interface signature:** installed maatwebsite `WithMapping::map($row)` has an
   untyped param; PHP 8.4-style variance rejected `map(Payment $payment)`. Widened to
   `map($payment)` with a `@var` docblock.

## Test results (what was verified vs not)
- New tests: 13/13 pass (5 unit + 8 feature). Full suite: **324 passed / 1088 assertions** (all
  green, no regressions — the filter fix is covered by the new feature test).
- Feature tests assert real downloaded CSV bytes: Livewire `callAction('exportCsv')` →
  `effects['download']` base64-decoded → `str_getcsv` rows; filename asserted against
  `payments-\d{4}-\d{2}-\d{2}-\d{6}\.csv`.
- NOT verified: manual click-through in the browser (Export button UI rendering), very large
  row counts (FromQuery chunks; row volumes are small per project assumptions), XLSX path
  (out of scope by decision).

## Known gaps / next step
- Next unchecked item (same section): Service connections CSV export
  (`App\Exports\ServiceConnectionsExport`, status/barangay filters, same button pattern).
- `graphify . --update` not run (no structural change — new file + method-visibility edits; can
  refresh when the next export lands).
- No commit made (needs explicit user approval).
