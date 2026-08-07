# 2026-08-07 — Create-connection flow audit & fixes (rate-schedule duplicates, broken retry)

**Goal:** Senior-engineer review of commit `24408c9` (create-new-connection flow) driven by a
reported UI bug — the Rate Schedule dropdown showed two identical "Standard Flat Rate" options.

## What was found

1. **Duplicate "Standard Flat Rate" (and a "minus Rate 2001") in the dev DB** were NOT produced by
   the last commit — they are stale data. Row id 2 predates the idempotent-seeder guard (`b45ee74`,
   re-running `db:seed` inserted it); row id 3 was a manual-test schedule tied to incomplete dev
   connections (17/18, zero invoices/readings/links/payments). The new create form merely surfaced
   them. No DB constraint prevented duplicates, and none should be added (see product-decisions §30).
2. **The shipped `23505` retry in `handleRecordCreation` could never work.** Filament's
   `CreateRecord::create()` wraps the whole save in a single `DB::transaction` (BeginRecord.php:101).
   A Postgres unique violation aborts that transaction (`25P02`), so the follow-up
   `nextIdentifier()` query — and even the page re-render — threw instead of retrying. Confirmed by
   repro. No test ever exercised this path (that's why it shipped broken).
3. **Column detection bug in my first fix:** the exception message contains the names of *all*
   columns in the INSERT, so substring matching picked the last match, not the fired constraint.
   The real constraint is in the `DETAIL:  Key (column)=…` line — parsed from there now.
4. **Partial unique index (`WHERE effective_to IS NULL`)** proposed in the plan was dropped: it
   contradicts legitimate same-name open-ended schedules and broke `BillingServiceTest` immediately.

## Files changed

- `backend/app/Filament/Resources/ServiceConnectionResource.php` — Rate Schedule dropdown label
  disambiguation (`name · effective_from`) via Filament v5 `getOptionLabelFromRecordUsing`.
- `backend/app/Filament/Resources/ServiceConnectionResource/Pages/CreateServiceConnection.php` —
  retry now wraps each save in its own nested `DB::transaction` (SAVEPOINT), regenerates only the
  colliding column (from `DETAIL:  Key (…)`), only when it still looks machine-generated
  (`/^(?:GW|MTR)-\d+$/`); hand-typed values are preserved; exhaustion or non-generated collision →
  `ValidationException` form error instead of a raw 23505.
- `backend/database/migrations/2026_08_07_100000_cleanup_rate_schedules_table.php` — collapse
  duplicate same-name schedules (keep lowest id, repoint connections/invoices/tiers), delete the two
  guarded test connections (zero dependents), delete orphan schedules; `down()` is a documented no-op.
- `backend/tests/Feature/ServiceConnectionResourceTest.php` — `test_create_rolls_forward_suggested_identifier_on_concurrent_collision`
  (direct `handleRecordCreation` call with a pre-existing competitor row), `test_...admin_typed_identifier_surfaces_form_error`,
  `test_rate_schedule_select_labels_disambiguate_same_name_schedules`.

## Bugs fixed (root cause, not symptom)

- Visible "two Standard Flat Rate" / stray schedules → data cleanup migration + dropdown
  disambiguation. Constraint rejected on purpose.
- Retry path dead on arrival → SAVEPOINT-per-save.
- Retry silently clobbered both identifiers → column-level roll-forward + preserve typed values.

## Test results

- Full suite green: **386/386 passed** (incl. the 3 new tests), checked with the relied-on invocation
  `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit`.
- Dev DB verified after `php artisan migrate`: `rate_schedules` → single "Standard Flat Rate";
  15 connections remain (both incomplete test connections removed).

## Known gaps / next steps

- The two retry tests call the protected `handleRecordCreation` via reflection (bypassing the form's
  synchronous `unique()` validation). A true end-to-end concurrent simulation (two Livewire page
  sessions racing) is worth adding later if the office ever runs >1 concurrent creator.
- `createAnother()` after a roll-forward was reasoned through but not separately asserted.
- Manual check: open `/admin/service-connections/create` — the Rate Schedule dropdown must show a
  single "Standard Flat Rate · 2026-01-01".

## Commit

Not committed (no explicit request). Files are staged/working as listed above; commit suggested at
next natural checkpoint.