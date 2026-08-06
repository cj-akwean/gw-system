# 2026-08-06 — Service Connections CRM (Admin Panel item 2)

## Goal
Implement `ServiceConnectionResource` in Filament: CRM list / view / edit (no create/delete),
plus wiring dashboard stat-card deep-links to the two feasible filtered views.

## Key decisions (user-confirmed)
- Identifiers (`account_number`, `meter_number`) ARE editable; linked portal users notified by
  email on change (admin gets in-page toast with notified count).
- Dashboard deep links: "Active customers" → filtered connections list; "Revenue this month" →
  filtered payments list. The 3 invoice-based deep links (unpaid/overdue/outstanding) deferred to
  the InvoiceResource item — they would 404 against a non-existent resource.

## Files created / modified
| File | What |
|---|---|
| `backend/app/Filament/Resources/ServiceConnectionResource.php` | Customers-group resource; form/table/filters; `canCreate=false`, `canDelete=false`; routes `index=/`, `view=/view/{record}`, `edit=/{record}/edit` |
| `backend/app/Filament/Resources/ServiceConnectionResource/Pages/{List,View,Edit}ServiceConnection.php` | View has "Edit" header action; Edit snapshots prior identifiers in `beforeSave`, `afterSave` calls service + success toast |
| `backend/app/Filament/Resources/ServiceConnectionResource/RelationManagers/{MeterReadings,Invoices}RelationManager.php` | Relation tabs on view |
| `backend/app/Services/ServiceConnectionService.php` | `handleIdentifierChange()` (dispatch, returns notified count) + `recipientsFor()` |
| `backend/app/Mail/ConnectionIdentifiersChanged.php` | `(ServiceConnection, array $oldIdentifiers)` |
| `backend/app/Jobs/SendConnectionIdentifierChangedEmail.php` | `(ServiceConnection, array $oldIdentifiers)`, tries=3, backoff [10,30,60], paymongo log, `failed()` → admin Notification |
| `backend/resources/views/emails/connection-identifiers-changed.blade.php` | old → new table, portal unaffected note |
| `backend/app/Filament/Widgets/MetricsOverview.php` (M) | deep links on 2 stat cards (rawurlencoded JSON `filters` query param) |
| `backend/tests/Feature/ServiceConnectionResourceTest.php` | 8 tests |
| `backend/tests/Feature/DashboardWidgetsTest.php` (M) | deep-link test |
| `ARCHITECTURE.md` | item checked; `docs/insights/product-decisions.md` §24; this summary |

## Bugs found & fixed (root cause, not symptom)
1. **View page 500 on `/create`.** Filament v5 page routes register with NO constraints → default
   `/{record}` matches `create`, binds non-numeric as int → Postgres 500. Fixed by registering
   view at `/view/{record}`; test pins `/create` → 404.
2. **`getOriginal()` in `afterSave` returns new values.** Eloquent `syncOriginal()` runs on save, so
   old identifiers were lost by the time the service ran. Fixed: snapshot in `beforeSave`, pass to
   service.
3. **Test asserted unchanged identifiers too.** After the array_filter, only *changed* identifier
   keys are in `oldIdentifiers`; test expected both keys. Updated expectation to
   `['account_number' => 'GW-OLD-001']`.
4. **`assertSee('Viewable Customer')` failed on view page.** Disabled form inputs render no
   `value=` text in HTML (state lives in wire:model); dropped the assertion, kept heading + tab labels.
5. **Job-property name collision avoided** by naming the model property `serviceConnection`
   (not `connection`) on the job — `Queueable::$connection` trait property collision.
6. No queue `connection` leaks: job uses `Queueable::$connection` correctly renamed.

## Test results (what was verified vs not)
- 14 targeted tests pass (8 resource + 1 widget + prior). Full suite: **250 passed / 737 assertions**.
- `test_identifier_change_dispatches_...` verifies dispatch with old account_number only;
  toast-count path verified by service call in page.
- NOT verified: actual email rendering/HTML (only dispatch); manual UI click-through of deep links
  (asserted via widget HTML + resource URL + Livewire::withQueryParams filter roundtrip).

## Known gaps / next step
- Next unchecked item: Admin Panel item 3 — `InvoiceResource` (list by status, view detail/breakdown,
  mark paid) + "Run billing" page. Deferred dashboard deep links (unpaid/overdue/outstanding) land
  with it.
- No commit made (needs explicit user approval).

## Graph
- `graphify . --update` not yet run this session (structural change: new Resource + pages + service).