# Billing Decisions & Logic — Catalog for Verification

Everything decided for the **Billing phase** (checklist item 1: `App\Services\BillingService`),
written as **Question → Decision → Status → Code ref** so each decision can be verified —
either against the running system, or against the **Guinobatan Waterworks office** when
the real-world value needs confirming.

**Status legend:**
- **Confirmed** — you (project owner) decided this; it's implemented and tested.
- **Assumption (verify with office)** — seeded/implemented value or behavior that should
  be checked against the real waterworks practice before it becomes "true".
- **Deferred** — explicitly postponed; not implemented.

---

## Part 1 — Confirmed decisions (implemented + tested)

### 1. Which rate does a connection get billed at?
**Question:** Should billing use one global rate, or per-connection rates?
**Decision:** `ServiceConnection.rate_schedule_id` (nullable FK). Billing uses the
connection's own schedule **if it is effective for the billing period**; otherwise it
falls back to the single globally-active schedule. If no schedule is effective at all,
the connection is skipped + reported.
**Status:** Confirmed.
**Code ref:** `BillingService::findEffectiveSchedule()` (BillingService.php); skip row in
`run()` ("No effective rate schedule for this period.").
**Office-verify note:** Residential vs commercial rate classes are real-world — deferred
(see Part 3). When the office confirms classes, this becomes data, not a billing-code
change (Invoice already snapshots `rate_schedule_id` per bill).
**Ops note:** the seeder assigns the schedule only to connections created after it runs.
On an existing DB, assign it manually:
`App\Models\ServiceConnection::whereNull('rate_schedule_id')->update(['rate_schedule_id' => App\Models\RateSchedule::first()?->id]);`
(rate lookups are deterministic on ties: highest `effective_from` wins, then highest `id`).

### 2. What happens to flagged readings (meter replacement, present < previous)?
**Question:** A flagged reading can hold negative `cu_m_used`; billing it produces a
negative/zero bill. What should billing do?
**Decision:** SKIP + report ("Flagged reading (level N) — investigate, then bill
manually."). Billing math can never see a flagged reading. Office investigates and the
bill is written manually/offline. Rare event (<10/year by your estimate), so no
automation.
**Status:** Confirmed.
**Code ref:** `run()` flagged check (`$reading->flagged !== 0`), before all math.
**Office-verify note:** Flag levels: `0` = clean, `1` = CSV/manual flag, `2` = auto-flagged
(`present < previous`).

### 3. What happens to connections with no reading in the period?
**Question:** No minimum-charge field exists in the schema, and real bills carry a minimum
monthly charge. Bill them at 0.00 anyway?
**Decision:** SKIP + report ("No reading in the billing period (…)."). First-month
connections pay offline over the counter, then join the online cycle. A 0.00 invoice is
just noise. Minimum charge deferred until the office confirms a real value.
**Status:** Confirmed.
**Code ref:** `run()` no-reading branch.
**Office-verify note:** Ask the office for the actual minimum charge value (see Part 3).

### 4. What happens to a zero-usage reading (meter didn't move)?
**Question:** Reading exists with `cu_m_used = 0` (e.g. vacant property, vacation). Should
billing create a ₱0.00 invoice that lingers as 0.00 arrears and flips to "overdue"?
**Decision:** SKIP + report ("Zero usage — verify meter locked/closed, or bill
manually."). The office can lock/close the physical meter for long-vacation accounts —
that's an offline workflow; the report row tells the office to verify. No 0.00 noise
invoices.
**Status:** Confirmed (Aug 2026 robustness pass).
**Code ref:** `run()` zero-usage branch (`$cuMUsed == 0`), after the already-billed check.
**Office-verify note:** If the office ever introduces a minimum monthly charge, zero-usage
handling likely changes to "bill the minimum" — revisit then.

### 5. What if billing math input is invalid (unflagged negative usage, misconfigured schedule)?
**Question:** Data-entry hole: reading with negative `cu_m_used` but `flagged = 0`; or a
flat schedule with no rate; or a tiered schedule with no tiers. Silently bill 0.00 /
negative, or abort the whole run?
**Decision:** SKIP + report, one account at a time — the run completes and the report
names the account: "Non-positive usage (X cu.m.) — investigate, then bill manually." /
"Rate schedule misconfigured (…) — missing flat rate or tiers." The run does NOT abort.
**Status:** Confirmed (Aug 2026 robustness pass).
**Code ref:** `run()` guards + `scheduleCanCompute()`.
**Office-verify note:** None — this is a data-integrity guard, not a business rule.

### 6. How is the monthly penalty computed?
**Question:** Penalty on unpaid balance — simple or compound? When does it start?
**Decision:** **Compound, 2% per month** on each unpaid invoice's full `total_amount`
(including previously accrued penalty — "interest on unpaid balance", like real PH
utilities). Starts after `due_date + grace_period_days`; only **full 30-day buckets**
count (partial months accrue nothing). Penalty is folded into the new invoice's
`penalty_amount`, never appended to the old one. Stored as data (`PenaltyRule`:
`percent_per_month`, `grace_period_days`), not hardcoded.
**Status:** Confirmed (compound locked in by regression test).
**Code ref:** `BillingService::computePenalty()` + `billConnection()`.
**Office-verify note:** Confirm the 2% value, the 15-day grace, whether partial months
count, and whether there is any cap (see Part 2). If the office ever wants simple
interest, each invoice's principal must be tracked separately — it isn't today.

### 7. How do arrears carry over to the next bill?
**Question:** What appears on the new invoice for unpaid previous bills?
**Decision:** `previous_balance` = sum of all unpaid invoice totals (status
unpaid/overdue, per connection, ordered by due date). New invoice total =
`previous_balance + penalty_amount + base_amount`. The breakdown stays as separate
columns — the "show your work" structure for bill disputes.
**Status:** Confirmed.
**Code ref:** `getUnpaidInvoices()` + `billConnection()`.

### 8. What is the billing window and which reading wins?
**Question:** One run per calendar month — which readings qualify?
**Decision:** `period_end` = last day of the billed month (or explicit `--period=YYYY-MM-DD`).
Window = the exact calendar month of `period_end` (reads from month start to end of
`period_end` day, timestamps inclusive). The **latest reading in the window wins** (the
30-day reading-gap rule keeps this to at most one meaningful reading per cycle).
**Status:** Confirmed (calendar-window bug fixed + regression-tested).
**Code ref:** `run()` period math + reading query.
**Office-verify note:** None — internal cycle decision.

### 9. Is a re-run safe?
**Question:** Running `billing:run` twice for the same month — duplicates?
**Decision:** Idempotent: a reading already covered by an invoice is skipped ("Already
billed for this reading."). Enforced twice: app-level check in `run()` **and** a DB
unique constraint on `(service_connection_id, meter_reading_id)` — a concurrent run that
slips past the app check fails loudly (unique violation, whole run rolls back) instead of
silently double-billing.
**Status:** Confirmed (constraint added Aug 2026 robustness pass).
**Code ref:** `run()` already-billed check + migration
`2026_08_02_000003_add_unique_service_connection_reading_to_invoices_table`.

### 10. When does an unpaid invoice become "overdue"?
**Question:** Status semantics — when does unpaid → overdue?
**Decision:** At each run, invoices with `status = unpaid` and `due_date < period_end`
are marked `overdue`. Note: this ignores the grace period — status flips at due date, but
penalty (the thing that matters for money) still starts only after due + grace.
**Status:** Confirmed.
**Code ref:** `run()` overdue pass.

### 11. Are inactive connections billed?
**Question:** Disconnected/inactive accounts still get readings entered — bill them?
**Decision:** No — only `status = 'active'` connections are billed; inactive ones don't
even appear in the report.
**Status:** Confirmed.
**Code ref:** `run()` connection query.

### 12. Is the billing run atomic?
**Question:** A mid-run failure (e.g. a bug) leaves partial invoices — acceptable?
**Decision:** No — the whole cycle runs inside one `DB::transaction()`; any failure rolls
back everything (overdue marks + new invoices). Idempotency is the second safety net.
**Status:** Confirmed (atomicity fix in code; regression test added — `test_run_rolls_back_everything_on_mid_run_failure`
proves a mid-run exception persists NO invoices and reverts the overdue pass).
**Code ref:** `run()` `DB::transaction()` wrapper.

### 13. How is the run triggered?
**Question:** CLI or queue?
**Decision:** `php artisan billing:run` (manual), default period = last day of the
previous month; `--period=YYYY-MM-DD` overrides. Since checklist item 2 (Aug 2026) the
command **dispatches a queued job by default** and `--sync` runs inline (see decision 22).
Invalid periods (bad format **or** impossible
calendar dates like 2026-02-31) are rejected — exit code 1, nothing billed.
**Status:** Confirmed.
**Code ref:** `BillingRunCommand::isValidPeriod()`; `run()` throws
`InvalidArgumentException` on bad dates.

### 14. What format are invoice numbers?
**Question:** Numbering scheme?
**Decision:** `GW-YYYY-XXXXX`, global sequence derived from the highest invoice `id`
(creation order) + 1. Collision-proof past 9 invoices; does **not** reset each year
(cosmetic; unique constraint catches any race loudly). The earlier version ordered by
`invoice_number` — a *lexicographic* sort, so `GW-2026-00010` sorted below `GW-2026-00009`
(`'9' > '1'`) and every number past 00009 repeated 00010, violating the unique
constraint; that's the bug the regression test guards against (see product-decisions.md §12).
**Status:** Confirmed (collision bug fixed + regression-tested).
**Code ref:** `generateInvoiceNumber()`.

### 15. When the connection's assigned schedule is expired, does the report say so?
**Question:** Silent fallback to the global rate could mask a config error.
**Decision:** The billed row notes it: "Global rate (assigned schedule not effective for
this period)." Fallback behavior itself unchanged (per-connection first, global second).
**Status:** Confirmed (Aug 2026 robustness pass).
**Code ref:** `findEffectiveSchedule()` `$usedFallback` out-flag; `run()` billed-row
reason.

### 16. Which penalty rule applies to old invoices?
**Question:** Penalty rules can change over time — per-invoice history or current rule?
**Decision:** One effective rule per run (as of `period_end`) applies to ALL outstanding
invoices of that run — a rule change applies retroactively to everything still unpaid.
Historical per-invoice rule snapshots are not stored (Invoice has no `penalty_rule_id`).
Simplification, accepted for MVP. Deterministic on ties: highest `effective_from` wins,
then highest `id` (last-created wins).
**Status:** Confirmed.
**Code ref:** `run()` → `findEffectivePenaltyRule($periodEnd)`.
**Office-verify note:** Ask whether rule changes apply retroactively to outstanding bills.

### 17. What if no PenaltyRule exists at all?
**Question:** No penalty rule seeded/configured — what due date do invoices get?
**Decision:** Grace falls back to a hardcoded 15 days for the due-date computation
(`?? 15`); penalty is simply 0.00 everywhere (no rule → no penalty). Known trade-off:
15 is a duplicated magic number — revisit when admin UI manages PenaltyRules.
**Status:** Confirmed (known gap, deliberately not "fixed" with more magic).
**Code ref:** `billConnection()` due-date line.

### 18. What does the billing run report?
**Question:** How does the operator know what happened?
**Decision:** Table per connection: account, status (billed/skipped), reason, invoice
number, total. Billed / skipped counts at the end. Every skip names the account and the
reason — that report is the office's action list (flagged → investigate, no reading →
check, zero usage → verify meter).
**Status:** Confirmed.
**Code ref:** `BillingRunCommand` table + `run()` report rows.

### 19. What if a new reading supersedes a billed one for the same period?
**Question:** The already-billed guard is per-reading. If a *new* reading replaces one
that was already billed (same month), billing would create a second invoice for that
month — double-billing the customer.
**Decision:** Accepted as-is for now. The guard stays per-reading; a period-level
"already billed this month" guard is NOT implemented. Protection comes from the
data-entry layer: the 30-day reading-gap rule (Meter Readings phase) blocks backdated
superseding readings in practice. If one does slip through (office re-enters a reading
for an already-billed month), the office reconciles manually — both invoices show in the
report and in the customer's unpaid list.
**Status:** Confirmed (known gap, deliberately accepted for MVP).
**Code ref:** `run()` already-billed check (keyed on `meter_reading_id`).

### 20. When the Payments phase adds new invoice statuses, does arrears logic hold?
**Question:** `getUnpaidInvoices()` counts only `unpaid` + `overdue`. The Payments
phase (PayMongo webhook + offline payment recording) will introduce `paid`, and possibly
`partial`/`pending`. Will arrears carryover still be right?
**Decision:** Not touched yet — flagged as a MUST-REVISIT when the Payments phase lands.
Preferred direction: keep the invoice status binary (`unpaid`/`paid`/`overdue`) and
record partial payments as `Payment` rows, so the sum of unpaid totals stays correct
without touching `getUnpaidInvoices()`. If partial payment becomes an invoice *status*
instead, arrears math changes.
**Status:** Confirmed (future-sync flag, not implemented).
**Code ref:** `getUnpaidInvoices()`.

### 21. What index supports the per-connection reading lookup?
**Question:** Every billing run queries readings per connection within a date window
(`run()` loop). Is the schema indexed for it?
**Decision:** Composite index on `meter_readings (service_connection_id, entered_at)`
added with the Billing phase (migration `2026_08_02_000002`). It sits alongside the
Meter Readings phase's unique expression index `(service_connection_id, entered_at::date)`
— the two serve different purposes: the composite one accelerates the billing window
query, the unique one enforces the one-reading-per-date data rule.
**Status:** Confirmed.
**Code ref:** `run()` reading query + migration `2026_08_02_000002_add_composite_index_to_meter_readings_table`.

### 22. How does the queued billing job run, and where does its report live?
**Question (checklist item 2):** the run must not block a request, and the command can no
longer print the report synchronously — where does the per-connection report go?
**Decision:** `php artisan billing:run` **dispatches `App\Jobs\RunBillingJob` by default**
(queued, `database` driver); `--sync` keeps the old inline behavior for tests/manual
verification. Every run — queued **or** sync — writes a row to a new `billing_runs` table
(`period_end`, `status` running/completed/failed, `report` JSONB, `error`, `finished_at`):
a durable audit trail for a money-critical flow, survives cache clears, and is what the
Admin Panel phase's "Run billing" page will read. A Postgres **partial unique index**
(`billing_runs (period_end) WHERE status = 'running'`, migration `2026_08_03_000001`)
blocks two concurrent runs for the same month even across a check/insert race; the command
also pre-checks and refuses with exit 1. Completed/failed rows don't block re-runs
(idempotent re-runs stay possible). `php artisan billing:report {id}` prints a stored
report without a UI. The job retries up to 3 times (`$tries = 3`) — safe because a failed
run persists nothing (`run()` is one transaction) and re-execution is idempotent; the run
row is re-marked `running` on retry. The monthly **scheduler** wiring is deferred to the
Infra phase (needs a host running cron + a worker).
**Status:** Confirmed (Aug 2026, checklist item 2 — 72/72 tests green; live-verified:
dispatch → `queue:work --once` → completed report; duplicate-running refused).
**Code ref:** `RunBillingJob`, `BillingRunCommand` (`--sync`, running pre-check),
`BillingReportCommand`, migration `2026_08_03_000001_create_billing_runs_table`.

### 23. What happens when a billing run races, goes stale, or retries? (robutness pass on decision 22)
**Question:** The queued-run design (decision 22) was reviewed for failure modes —
what fails when two `billing:run` calls race, when a dispatched job is never picked up,
and when the job retries?
**Decision:** three failure modes closed (Aug 2026 robustness pass):
1. **Concurrent-create race**: the command pre-check and the `BillingRun::create()` are
   non-atomic — two simultaneous invocations could both pass the check and the loser
   would hit the partial unique index with a raw `QueryException`. The create is now
   wrapped in a `try/catch (UniqueConstraintViolationException)` that prints the same
   friendly "already in progress" message and exits 1 (DB backstop stays authoritative).
2. **Stuck `running` rows**: a dispatched job the worker never picks up leaves a `running`
   row that the partial unique index then lets block that period *forever*. Recovery is a
   new `billing:run --force`: it flips a **stale** `running` row (`created_at` older than
   `BillingRun::STALE_AFTER` = 10 hours) to `failed` (with an "Abandoned run — forced
   failed" audit error) and starts a fresh run. A *fresh* running row is never touched —
   an admin only forces after confirming the worker is actually dead. No auto-recovery:
   a genuinely slow-but-alive run must not be double-billed by the system.
3. **Immediate retries**: `RunBillingJob` now backoffs `[30, 60, 120]` seconds instead of
   retrying instantly three times.
`billing:report` now prints how long a `running` run has been going and, if it exceeds
`STALE_AFTER`, warns it may be abandoned and suggests `billing:run --force`.
**Status:** Confirmed (78/78 tests green: unique-constraint collision, `--force` stale
recovery, `--force` refuses fresh rows, `--force` stale hint, backoff).
**Code ref:** `BillingRunCommand::handle()` (`--force`, try/catch), `BillingRun::isStale()`
+ `STALE_AFTER`, `RunBillingJob::$backoff`, `BillingReportCommand` stale hint.

### 24. What does a queued billing job do when it races, is force-abandoned, or is redispatched for the wrong period? (robustness pass 2 — source-of-truth & job resilience, Aug 2026)
**Question:** Decision 23 closed the obvious holes (concurrent `create` race, stuck rows, backoff) and tests passed 78/78. A focused re-audit of `RunBillingJob::handle()` found four more failure modes the suite did **not** cover.
**Decision:** four fixes (Aug 2026, 82/82 tests green):
1. **Wrong-period dispatch.** `RunBillingJob::handle()` now reads the period from the run row (`$run->period_end`), not the constructor argument; if a caller dispatches with a mismatched `periodEnd`, the job marks the run `failed` with a "Mismatched period … refusing to bill the wrong month." audit error and **returns without retrying** (permanent logic error, captured on the row). Prevents money being billed for month B against an audit row (and unique-index guard) for month A.
2. **Job update tripping the partial unique index on a live constraint.** The "reset to `running`" update now runs **inside the `try`**, and the collision path `catch (UniqueConstraintViolationException)` marks the run `failed` ("Superseded … not resumed") instead of surfacing a raw SQL error to the worker.
3. **Probe before colliding.** The job now **SELECT-probes** for any *other* `running` row for the same `period_end` before attempting the reset. The common superseded case (operator ran `billing:run --force`, or a retry after a fresh run) is therefore handled by a clean `SELECT`, not by deliberately violating the unique index. The `catch` remains as a backstop for a true race (two jobs both passing the probe), which only manifests in production where there's no outer test transaction.
4. **Never resurrect a force-failed run.** A run whose `error` contains "forced failed" (the operator `--force` audit string) is treated as terminal: an old, delayed job for that row logs "force-failed by an operator — not resuming" and returns, leaving the audit row as `failed`. The retry path that clears a *normal* failure still works (only `force failed` rows are shielded).
Also: an unknown/zero `billingRunId` now throws a clear `RuntimeException` instead of looping retries on a `ModelNotFoundException`.
**Status:** Confirmed (78/78 → 82/82, 223 → 238 assertions).
**Code ref:** `RunBillingJob::handle()`, `RunBillingJobTest::test_job_refuses_to_bill_the_wrong_period`, `test_job_does_not_resurrect_a_force_failed_run`, `test_job_fails_cleanly_when_a_newer_run_holds_the_period`, `test_job_guard_billing_run_not_found`.

---

## Part 2 — Assumptions to verify with the Guinobatan Waterworks office

These are implemented/seeded values or behaviors based on research or your defaults.
Each should be confirmed with the office; the question is phrased exactly as to ask.

| # | Assumption in the system | Question for the office | Where to look |
|---|---|---|---|
| A1 | Flat rate **₱10.00 / cu.m.** (seeded, all connections) | What is the actual per-cubic-meter rate, and is it tiered? | `RateScheduleSeeder` |
| A2 | Grace period **15 days** (seeded; also the no-rule fallback) | How many days after the due date before penalty starts? | `PenaltyRuleSeeder` |
| A3 | Penalty **2% per month, compounding on the full unpaid total, no cap** | Is the penalty exactly 2%/month? Compound or simple? Does it cap (e.g. 10%)? Do partial months count? | `computePenalty()` |
| A4 | `disconnection_after_days = 60` (seeded, **not used in billing math yet**) | How many days after due does disconnection actually happen? | `PenaltyRuleSeeder` |
| A5 | **No minimum monthly charge** (deferred) | Do bills carry a minimum charge (e.g. first X cu.m. at fixed amount)? What is it? | ARCHITECTURE.md Deferred |
| A6 | **Single rate class** for all connections (deferred) | Do residential and commercial accounts bill at different rates? | ARCHITECTURE.md Deferred |
| A7 | Penalty buckets = **30-day blocks** (not calendar months) | Is penalty per calendar month, or per 30 days? | `computePenalty()` |
| A8 | **30-day gap** between readings (Meter Readings phase rule) | How often are meters actually read / billed? | `ReadingService` |
| A9 | Zero-usage accounts are **skipped**; office can lock/close the physical meter for vacation accounts | How does the office currently handle accounts with zero consumption (vacation, locked meters)? | `run()` zero-usage branch |
| A10 | Due date = **period end + grace days** (15) | When is the bill actually due? Does the office move due dates off weekends/holidays? | `billConnection()` due date |
| A11 | Penalty **accrues across skipped (no-reading) months** — it catches up on the next bill because months are counted from the due date | Does penalty keep accruing in months where the account wasn't billed (no reading)? | `computePenalty()` (months from due date) |
| A12 | Meter **malfunction / abnormally high readings → billed manually/offline today**; a data-driven **estimate rule is deferred** (documented in Part 3; heard from an electricity-co-op context — last correct bill, or highest of the last 3) | When a meter malfunctions or a reading comes out impossibly high (e.g. unnoticed buried leak), how does the office settle the bill — last correct bill, highest of last 3, average of last 3, or a one-time waiver? Does it apply only to flagged readings, or to disputed-but-unflagged ones too? | Part 3 (deferred estimate entry) |

### Quick printable checklist (bring to the office)
1. Rate per cu.m. — flat or tiered blocks? Exact figures.
2. Grace period in days after due date.
3. Penalty: percentage per month, compound vs simple, any cap, partial-month rule.
4. Disconnection timeline (days after due).
5. Minimum monthly charge — value?
6. Residential vs commercial rate difference.
7. Penalty per calendar month or per 30 days?
8. Actual meter-read/billing cadence.
9. Handling of zero-consumption / vacant / locked-meter accounts.
10. Due date convention — weekends/holidays?
11. Does penalty accrue in months the account wasn't billed (no reading)?
12. **Meter malfunction / impossibly high reading (e.g. buried leak): how is the bill settled — last correct bill, highest of last 3, average of last 3, or one-time waiver? Applies to flagged readings only, or disputed ones too?**

---

## Part 3 — Deferred (explicitly not implemented)

- **Minimum monthly charge** — schema has no `minimum_charge` field; add only when the
  office confirms the value (then zero-usage handling may change too).
- **Residential vs commercial rate classes** — future `rate_class` column + commercial
  schedule seed; data-only change, no billing-code change needed.
- **Simple-interest penalty** — if the office wants it, per-invoice principal tracking
  must be added.
- **Manual invoice entry UI** — flagged / zero-usage / misconfigured accounts are billed
  "offline" today; the Admin Panel phase needs a billing view to record those manual
  invoices in-system.
- **Queued billing job** — DONE (checklist item 2, decisions 22–24; the job calls the same `run()`; robustness passes 23 & 24 — race handling, `--force` stale recovery, retry backoff, and job-level source-of-truth / superseded / force-failed guards). Remaining queued-work items: PDF generation (checklist item 3) and the monthly scheduler wiring (Infra phase).
- **Offline/manual payment recording** — tracked under Payments checklist.
- **Estimated billing for malfunctioning / abnormally high meters** (documented
  Aug 2026, user-raised; see product-decisions.md §14). Today flagged readings are
  investigated + billed manually/offline. The deferred feature is a **data-driven
  estimate rule** (e.g. settle at the last correct bill, or the highest of the last
  3 bills — the office must confirm which, and whether average-of-3 or a one-time
  waiver is their actual practice; office question A12). Integration point: the
  Admin Panel phase's **manual-invoice entry UI** — when a flagged reading is
  investigated, the screen *suggests* the estimate from history, the admin confirms,
  and the invoice records its basis. It is NOT an automatic branch inside
  `billing:run` — human confirms, system does arithmetic. "Unusually high but
  positive" readings are a separate case (leak detection → Smart Features section;
  readers can already flag them level 1 today). Not scheduled into the current
  billing phase.

---

*Maintained as part of the Billing phase (checklist item 1). Companion docs:
`docs/insights/product-decisions.md` (§11–§13 hold the original "why" narrative) and
`docs/summary/2026-08-02-billing-service.md`.*
