# 2026-08-06 — Service Connections identifier-email hardening (post-8887f19 review)

## Goal
Address 3 reliability gaps found in a deep review of 8887f19 (ServiceConnectionResource CRM):
toast-count drift, double-emailing on duplicate edits, delete-unsafe `failed()`.

## Files created / modified
| File | Change |
|---|---|
| `backend/app/Jobs/SendConnectionIdentifierChangedEmail.php` | implements `ShouldBeUnique`; `#[UniqueFor(3600)]`; `uniqueId()` = `conn-{id}-hash(oldIdentifiers+recipients)`; new ctor args `serviceConnectionId` (plain int) + `recipients` snapshot; `handle()` sends to snapshot; `failed()` model-free (logs + admin alert by id) |
| `backend/app/Services/ServiceConnectionService.php` | dispatch passes `$connection->id` and the resolved `$recipients` snapshot |
| `backend/tests/Feature/ServiceConnectionResourceTest.php` | dispatch test now pins `serviceConnectionId` + lowercase recipients; +2 tests: `uniqueId()` content scoping, duplicate-dispatch suppression (asserts `assertPushed(..., 1)`) |
| `docs/insights/product-decisions.md` | §25 decision record |

## Bugs found & fixed (root cause)
1. **Toast count vs actual send drift.** Service returned `count(recipients)` at save; job re-queried
   links at run time → count could differ. Root cause: recipient identity was recomputed twice.
   Fixed: recipients resolved once in the service, carried in the job payload.
2. **Duplicate emails on re-save.** No dedup: two saves of the same change → two emails. Fixed with a
   unique job lock taken at dispatch time (before the queue write), keyed per connection + content.
   Verified `DatabaseStore` (prod cache) implements `LockProvider`; dedup test proves `Queue::fake()`
   environment (array cache) suppresses the second dispatch (`assertPushed` = 1).
3. **`failed()` accessed a possibly-missing model.** Now uses a plain-`int serviceConnectionId`;
   admin alert + log are model-free.

## Test results (what was verified vs not)
- Full suite: **252 passed / 742 assertions** (was 250/737). 16 targeted tests in the two feature files.
- New dedup test verified lock suppresses identical second dispatch in the same process (array cache).
  NOT verified: unique-lock expiry/`UniqueFor` behavior against the real database cache (would need a
  live queue worker + db cache; lock semantics confirmed against framework source instead).
- NOT verified: actual email rendering/HTML (still only dispatch-level, unchanged from prior commit).

## Known gaps / next step
- Next unchecked item: Admin Panel item 3 — `InvoiceResource` + "Run billing" page; deferred dashboard
  deep links (unpaid/overdue/outstanding) land with it.
- `graphify . --update` still pending (structural graph refresh).