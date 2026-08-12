# 2026-08-12 — Inventory tab with low-stock notifications (admin)

## Goal

User request: "I need an inventory tab too, with notification if low on supplies — water pipe, PVC, etc."
After plan-mode Q&A: categories as an admin-editable lookup (barangay-style), items with free-text names
(brand/spec/price in the name, no structured price), Add Stock / Remove Stock (+/−) backed by an
append-only audit ledger, and low-stock alerts via the existing admin bell + Notification Hub, immediate
on crossing + a daily digest command. Uncommitted session.

## Files created / modified

**Backend (new)**
- `database/migrations/2026_08_12_000001..000003_create_inventory_{categories,items,transactions}_table.php` — CHECK constraints via raw SQL (`Blueprint::check()` does not exist in Laravel 13), case-insensitive unique names, FK RESTRICT, `quantity <> 0`
- `app/Models/InventoryCategory.php`, `InventoryItem.php`, `InventoryTransaction.php` (+ `signed_quantity` accessor, `isLowStock()`, `quantity_label`)
- `database/factories/Inventory{Category,Item,Transaction}Factory.php`
- `app/Services/InventoryService.php` — `recordTransaction()` (txn + `lockForUpdate`, overdraw block, 3-decimal cap, future-date rejection, boundary-crossing low-stock alert via `AdminNotifier`), `lowStockItems()`, `reconcileQuantities($fix)`
- `app/Console/Commands/InventoryCheckLowStockCommand.php` — daily aggregate digest, `--dry-run` / `--fix`
- `app/Filament/Resources/InventoryItemResource.php` (+ 4 pages, `InventoryTransactionsRelationManager`) and `InventoryCategoryResource.php` (+ 3 pages)
- `database/seeders/InventoryItemSeeder.php` — 9 categories + 19 items with opening receipts
- tests: `InventoryServiceTest` (17), `InventoryItemResourceTest` (16), `InventoryCategoryResourceTest` (6), `InventoryCheckLowStockCommandTest` (5)

**Backend (modified)**
- `routes/console.php` — `inventory:check-low-stock` daily 07:00 Asia/Manila, `withoutOverlapping`
- `database/seeders/DatabaseSeeder.php` — wired `InventoryItemSeeder`
- `tests/Feature/ScheduleTest.php` — +1 test pinning the new schedule entry

**Docs** — `ARCHITECTURE.md` (Inventory checklist), `docs/insights/implementation-notes.md` (Inventory §1), `docs/insights/product-decisions.md` (## 48), this summary.

## Bugs found & fixed

1. **`Blueprint::check()` does not exist in Laravel 13** — the `$table->check(...)` constraint calls died at migrate with `BadMethodCallException`. Switched to raw `DB::statement('ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)')`, mirroring the codebase's existing raw partial-unique-index pattern.
2. **Filament 5 relation managers on view pages are read-only by default** — the Transactions tab's "Record Movement" action was invisible (panel-wide `readOnlyRelationManagersOnResourceViewPagesByDefault = true`). Fixed by overriding `isReadOnly()` → false on the relation manager. Caught by the RM tests (`assertActionVisible`), not by eyeballing the UI.
3. **CreateRecord's save method is `create()` in Filament 5, not `save()`** — `->call('save')` on create pages threw "Public method [save] not found" (EditRecord still uses `save()`). All create-page tests call `create()` now.
4. **`ValidationException` thrown from a CreateAction `using()` closure does not surface as a form error** — the RM overdraw test failed with "Component missing error". Moved the overdraw check into the quantity field's validation rule (mirroring the service guard, which stays as the race-safe backstop).
5. **PS 5.1 `-Encoding UTF8` writes a BOM** — corrupted the test file's non-ASCII item names ("₱", "½″", "×") on a re-encode; restored via exact replacements and UTF-8-no-BOM writes. Watch for this in future PowerShell edits.

## Test results

- Inventory + Schedule filter: 66/66 green (was 53/66 on first run).
- **Full backend suite: 655/655 green** (2788 assertions, ~113s) via `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit`.
- `php artisan migrate --force` applied on dev DB; `InventoryItemSeeder` not yet run on dev (next step).

## Known gaps / next step

- Run `php artisan db:seed --class=InventoryItemSeeder` (or full `db:seed`) on dev, then demo:
  `php artisan inventory:check-low-stock --dry-run` (Water Meter ½″ seeds at 4/5 → shows the digest path).
- Manual admin check: log in at `/admin` → Inventory group → create an item, Add/Remove stock, watch the
  bell for the low-stock alert, verify the Notification Hub entry + action link.
- Dev DB migration already applied; nothing committed (user hasn't asked).

## Commit

None — uncommitted session (per repo rule: only commit when explicitly asked).

## Follow-up pass (same day) — first user test of the feature

User tested the inventory tab and reported: (1) seeded low Water Meter never in the bell/hub; (2) duct tape
status stayed "OK" after stock drops; (3) removing the last unit felt blocked with no "No stock" state;
(4) ledger shows a Reason column but Add/Remove modals had no Reason input; (5) reorder level wasn't
noticed on the create form.

Root causes: (1) **all Filament database notifications are queued (`ShouldQueue`) and dev has no worker** —
AdminNotifier rows sat in `jobs`; (2) reorder level silently defaulted to 0 = "never low"; (3) no zero
state existed; (4) reason field only existed on the Record Movement form; (5) reorder was optional with
default 0.

Fixes: inventory notifications now synchronous (`Notification::sendNow` in
`InventoryService::notifyAdminsSync` — same payload, bell/hub identical, no worker needed); seeder emits
the aggregate digest (Water Meter appears right after `db:seed`); reorder level required; 3-state status
(No stock/Low stock/OK) + "No stock only" filter; signed quantity display (issue shows -5); Reason input
on Add/Remove modals; removal to exactly zero allowed on both paths; inline category-create duplicate
rule (was a 500); initial_quantity precision rule + afterCreate rollback (was a 500 with orphan item);
RM owner-record refresh. Tested: full suite 665/665 green. Dev re-seeded; notification confirmed in the
`notifications` table immediately (2 admin rows), stale queued DatabaseNotification jobs purged from dev
`jobs`.
