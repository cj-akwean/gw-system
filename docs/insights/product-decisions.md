# Product Decisions & Domain Insights (GW-System)

> Pitching material + the "why" behind design choices. Each section records a real
> question asked during development and the reasoning behind the answer. These are
> the kinds of answers that show a product is built on actual domain understanding,
> not guesswork.

---

## 1. Why "flag" instead of "block" suspicious meter readings?

**Question asked:** "Why would I ever be allowed to enter a reading where present <
previous? Wouldn't it be better to just stop the wrong data from entering the table?"

**Answer — three reasons:**

**a) Meter replacement is a real, legitimate case.** When a physical meter is swapped
(common in PH utilities), the new meter starts at 0. So `present < previous` is
*correct*, not a mistake. Hard-blocking would make it impossible to record a valid
post-replacement reading without hacking around it (deleting/editing the old reading,
entering fake numbers). The flag lets the reading exist while marking it for human
verification.

**b) The field reader ≠ the data fixer.** Readings come in as bulk CSV from someone
walking the barangay. Blocking one bad row from entering at all would halt the whole
file. That's what "invalid/skip" already handles for *unambiguously wrong* data.

**c) A reading taken on the day is evidence you can't re-observe.** Once the walk is
done, yesterday's meter face is gone. Keeping the suspicious reading in the DB
(flagged) preserves it for disputes and audits; dropping it loses information
permanently.

**The line:** invalid = can't import at all (wrong account, negative value, future
date, duplicate) vs suspicious-but-plausible = imports + flag (present < previous).

**The flag is a follow-up workflow, not a rejection:** filter flagged → recheck the
meter → fix or clear the flag. This is why the CSV round-trip feature (download
preview with notes + flagged column, fix offline, re-import) exists.

---

## 2. What does "negative" mean in a meter reading? (user confusion)

Two different things, treated differently by the system:

| Case | Example | System behavior |
|---|---|---|
| Negative number on the meter face | `present_reading = -5` | Rejected as invalid (meters can't show negative) |
| Present lower than previous | present 50 vs previous 120 → cu.m. −70 | Imported but flagged (meter replacement scenario) |

---

## 3. How is a meter replacement handled today?

**Current workflow:** record the reading normally (e.g. new meter at 0, previous was
120). CSV import auto-flags it; manual entry flags it (auto-flag coming). It shows in
the list → filter "flagged" → verify the meter was indeed replaced → done.

**Why the flagged reading must never flow into billing (the swap-reading problem):**
after a meter swap, the swap reading stores `present 0 < previous 120` → `cu_m_used =
-120`. The chain self-corrects on the next reading (its previous = the new meter's
value), so the negative is transient — one reading only. BUT if that single -120 is
fed into billing math, it produces negative consumption → a negative/zero bill, which
breaks billing for that connection. So the billing service MUST define flagged-reading
behavior (skip / treat as 0 / manual override before billing). This requirement is
documented in the ARCHITECTURE.md Billing section so the Billing phase cannot forget
it.

**Deferred enhancement (noted in ARCHITECTURE.md):** a dedicated "meter replaced"
marker/note on the reading, so a backward reading isn't mistaken for an error by
someone else looking at the data. Deferred because the flag workflow already
functions; the marker is a labeling improvement, not a functional gap.

---

## 4. Why hard-block readings older than 30 days?

**Question asked:** "Shouldn't we prevent entering readings from more than 30 days ago?
This happens every month only, right?"

**Answer:** Yes — hard block. In a monthly-billing cycle, a reading dated more than
30 days in the past (or in the future) is a data-entry error ~99% of the time.
Two water bills within one month for the same connection is practically unheard of.

**Known edge case (deferred):** a reader who misses a month and records a 45-day
catch-up reading. Rare; can be revisited with a review/approval flow if it ever
becomes real.

**Correction (2026-07-31, evening):** the rule above was implemented wrong the
first time. It blocked dates **older than 30 days from today** — but that would
legitimately block a first reading backdated to before a new connection was
billed, and it let through two readings only a few days apart. The actual intent
is a **minimum 30-day gap since the connection's last reading** (monthly cycle:
you cannot bill the same account twice within one month). New rule, manual +
CSV: a reading is rejected when `reading_date < last_reading_date + 30 days`
(exactly 30 = allowed); future dates still rejected; **first readings are exempt**
(no age limit, so backdating a first reading stays legal). The gap is checked
against the DB's latest reading only — rows inside one CSV file don't affect each
other on a first upload.

**Second correction (same session):** CSV-declared `flagged` was conflated with
the automatic `present < previous` flag when generating the preview/download
note, so a row flagged only via the CSV column got the misleading "Present
reading is lower than previous" note even when the reading was higher than
previous. Notes now reflect the actual source: auto-flag → meter-replacement
text; CSV-only flag → "Flagged via CSV - no automatic issue detected". The flag
value itself is still `auto-flag OR csv-flagged` — the CSV column can add a flag
but never suppress the present < previous detection.

**Third correction (same session):** the `flagged` column went from boolean to
**flag levels** (`smallint`, 0/1/2) so a flag's *source* is visible, not just
that it exists: `0` = not flagged; `1` = flagged by CSV column or manual
override — no automatic basis detected (the note says exactly that, since the
system cannot prove a flag *incorrect*: tampering/dispute reasons are invisible
in the data); `2` = auto-flagged because `present < previous` (meter
replacement). Any non-zero level keeps meaning "suspicious" for the Billing
guard. Manual form is a 3-option Select (auto-sets 2, user can override to 0/1);
import preview + downloaded CSV show the level; a CSV `flagged` value only ever
sets level 1 — level 2 is system-reserved and the auto-detect fires even when
the file has no `flagged` column at all.

**Fourth correction (same session):** preview-time data could go stale between
preview and import — a row later in the same CSV file was stored with the
preview's previous reading and flag, so e.g. GW-00005's 25.00 imported after
75.00 in the same file landed **unflagged with cu_m_used = −50** (negative
usage never being flagged is a billing hazard). `createFromArray()` now
recomputes against the **actual latest reading at insert time**: stored
`previous_reading` is always the true one, and `present < previous` forces
level 2 — the auto-detect is ground truth at insert, so CSV/manual levels 0/1
only apply when present >= previous (the manual form's Select states this in
its helper text). The import preview remains a validation snapshot (it does not
simulate in-file order — consistent with the DB-only gap check); the DB is
truth.

**Fifth correction (same day):** the manual form could still save a second
reading for the same connection on the same date (reachable when the last
reading is ≥30 days old, or on double-submit — the gap rule only blocks within
30 days). Decided to fix it **twice over**: a form-level rule (good UX error
before save, on Create and Edit) AND a Postgres unique index on
`(service_connection_id, entered_at::date)` (absolute guarantee for every path
including CSV insert races and future API writes). Same-date dups are always
entry errors — no legitimate case for two readings of one meter on one day.
(The index needed a one-off data cleanup: the user's earlier bug-hunting left
two readings for GW-00002 on 07-31; one was deleted.)

---

## 5. The product replaces what happens AFTER the meter walk — not the walk

Meter reading stays a physical, in-person process (confirmed by real PH water bills —
a reader walks the barangay and reads each meter by hand; there's no automated
hardware). GW-System doesn't try to replace the walk — it replaces what happens
*after* the walk: data entry, validation, billing, collections.

This is honest product positioning: no fake IoT/smart-meter claims, just a better
back-office for a process utilities already run on paper.

---

## 6. Domain facts worth remembering (from real PH water bills reviewed)

- Real municipal water bills identify an account by **account number + meter number**,
  independent of who currently lives there (renters/boarders problem → ConnectionLink)
- Bill fields: **present / previous / cu.m. used** — GW-System's reading fields map 1:1
  to what customers already recognize from paper bills
- Some municipal systems bill a **simple flat rate per cu.m.** (e.g. ₱10/cu.m.), not
  tiered blocks — RateSchedule supports both
- **Penalty: 2% per month** interest on unpaid balance; disconnection after due date
  (stored as data, not hardcoded — municipalities change these)
- **Arrears** are carried forward month over month with accruing interest — Invoice
  stores `previous_balance` separately from `base_amount` so itemized breakdowns are
  transparent (customers dispute bills; show your work)
- Real municipal water bills do **not** have QR/online payment yet — that is the
  actual gap GW-System fills (PayMongo checkout per invoice)

---

## 7. Business logic must never live in the admin UI

- All billing/reading math lives in `App\Services\*` service classes — Filament is
  just a UI consumer. Models, migrations, and services are portable.
- AI/statistics (post-MVP) are **explanatory only** — billing math stays 100%
  deterministic, auditable line by line.
- Money-critical flows are manually tested; backups are automatic daily.

---

## 8. Filament 5.7 API reality check

Why the GW-System code looks the way it does — so future sessions don't "fix" it back
to v3/v4 style. All confirmed against installed vendor source (July 2026).

- **`form(Schema $schema): Schema`** — `Filament\Forms\Form` no longer exists; the
  schemas package (`Filament\Schemas\Schema` + `InteractsWithSchemas`) replaced it.
  Same for the import page: no `HasForms` — base pages already handle schemas.
- **Actions live in `Filament\Actions\*`** (`ViewAction`, `EditAction`,
  `BulkActionGroup`, `CreateAction`) — NOT `Filament\Tables\Actions\*`. The tables
  package only ships an enum there.
- **`defaultSort()` belongs to the Table, not the Column** — calling it on a
  `TextColumn` throws `BadMethodCallException`.
- **Custom resource pages with static slugs must be registered BEFORE `/{record}`**
  in `getPages()` — otherwise the record route swallows them and Laravel tries to
  cast the slug to a bigint (`SQLSTATE[22P02]` on Postgres is the symptom).
- **Resource property types must match the parent exactly** (e.g.
  `string | \UnitEnum | null` for `$navigationGroup`) — PHP property type variance is
  strict; a narrower `?string` is a fatal error at class-load time.

---

## 9. Empty dropdown ≠ broken code — check the data first

**Question asked:** "The Service Connection search field returns nothing — what do I put
in the input?"

**Answer:** nothing typed manually — it's a searchable dropdown over `service_connections`
(account number, meter number, or registered name). It returned nothing because the table
had **0 rows**: the whole meter reading feature was working against empty data.

**Lesson:** before debugging a UI widget, count the records it queries. Seeding gaps
masquerade as code bugs — and that's exactly what seeders exist for (15 connections now,
`GW-00001..15`).

**Related product note:** CSV reading dates need only a **date**, not a time — field
readers note the day, not the hour. Carbon parses date-only strings to midnight. The
manual form was switched from `DateTimePicker` to `DatePicker` so both entry paths accept
the same input.

---

## 10. Login error messages: differentiate the admin panel, keep the customer portal generic

**Question asked:** "When the password is wrong it says 'These credentials do not match
our records.' — make sure it's handled correctly on both the admin site and the customer
site."

**Answer:** the default message is Filament's (`filament-panels::auth/pages/login.messages.failed`),
thrown from `Filament\Auth\Pages\Login::authenticate()` for **two different reasons**: wrong
credentials AND valid-credentials-but-no-panel-access (`canAccessPanel()` false). A customer
email on `/admin` got a message that made it sound like their password was wrong.

**The fix (Aug 2026):**
- **Admin panel** (`App\Filament\Auth\Login`, registered via `->login(...)`): `authenticate()`
  is a copy of the v5.7.3 vendor method with the two failure points split —
  invalid credentials → "Incorrect email or password."; valid login but not an admin →
  "This account does not have access to the admin panel." (internal staff tool: no
  enumeration risk, clearer ops UX). NOTE: must be re-diffed on Filament upgrades.
- **Customer portal** (`POST /api/login`): one generic "Incorrect email or password." for
  both unknown email and wrong password. Deliberate: **email enumeration is a real
  security problem on public sites** — distinct messages let attackers harvest valid
  customer emails (targeted phishing, credential-stuffing optimization, reset-password
  spam against customers). Same generic message also on the admin panel's wrong-credential
  path; only the "you're not an admin" case is revealed there.
- **Rate limiting**: `/api/login` now has `throttle:10,1` (10/min per IP) — the admin panel
  already had Filament's built-in 5/min; public vs admin limits now actually differ per
  AGENTS.md.
- **Frontend**: `loginApi()` maps network failures to "Unable to reach the server. Please
  try again." instead of leaking the raw `Failed to fetch` TypeError.

**Correction (same session):** the session summary for 2026-07-31 claimed the `admins`
provider "filters where is_admin = true" — **that is dead config**. Laravel 13's
`EloquentUserProvider` constructor takes only hasher + model; a `where` key in
`config/auth.php` is ignored (verified in `vendor/laravel/framework/src/Illuminate/Auth/CreatesUserProviders.php`
and `EloquentUserProvider.php`). Admin gating actually works through
`FilamentUser::canAccessPanel()` in the login page + the `Authenticate` middleware's 403 —
defense in depth is fine, but the config is misleading. Left in place; removing it is
cosmetic only.

## 11. Billing: what gets billed, what gets skipped, and how arrears + penalty accrue

**Question asked (Aug 2026, Billing phase kickoff):** "I want to start billing — which
task should I do?" and the four follow-ups below.

**Answer — the three "skip" rules (all confirmed with the user, not invented):**

1. **Rate assignment = per-connection FK + global fallback.** `ServiceConnection` now has a
   nullable `rate_schedule_id`; billing uses it if effective for the period, else the single
   globally-active schedule. Rationale: MVP has one flat rate (₱10/cu.m. seeded), but the
   Invoice model already snapshots `rate_schedule_id` per bill, and real PH water districts
   bill **residential vs commercial at different rates** — a per-connection FK means that
   split (deferred, see ARCHITECTURE.md Deferred) is data, not another migration of billing
   code.
2. **Flagged readings (level 1 or 2) are SKIPPED and reported, never billed.** The flagged
   reading can hold negative `cu_m_used`; billing it makes a negative/zero bill. User's
   ground truth: meter swaps are rare — **well under 10 per year** — so automation adds
   complexity for almost nothing. The workflow is: skip → the report names the account →
   the office investigates → the bill is written **manually/offline**. Billing math simply
   never sees a flagged reading; there is no "treat as 0" or "manual override" code path
   yet, and none was built — that would be guessing consumption, which is worse than not
   billing.
3. **Connections with no reading in the period are SKIPPED and reported.** Real PH bills
   have a minimum monthly charge, but our schema has no minimum-charge field and the user
   confirmed first-month connections pay **offline over the counter** before entering the
   online cycle — so a minimum-charge column is deferred until the utility states a real
   value. Billing a zero-reading connection at 0.00 would just generate noise invoices.

**Penalty + arrears model (matches the real "ARREARS" table on PH bills):** at each run,
unpaid invoices past their due date become `overdue`; the new invoice then carries
`previous_balance` (sum of unpaid totals), `penalty_amount` (2%/month on each, computed
from its due date + 15-day grace, full 30-day buckets), and `base_amount` (usage × rate).
Total = all three. This is why Invoice stores the breakdown as separate columns — it's the
"show your work" structure for bill disputes, per the original design.

**Why `php artisan billing:run` exists (not a queued job yet):** the checklist's next item
is the queued job; the command exists now so the billing math is manually verifiable
end-to-end before it gets wrapped in the queue. The job will call the same `BillingService::run()`.

**Offline payments are a real gap, now tracked:** the user pointed out that the utility
takes most payments over the counter, and nothing in the system records who paid offline
(no admin UI, and PayMongo only covers online). Added as an unchecked Payments checklist
item ("Record offline/manual payments in admin") — needs the Admin Panel phase, then a
`Payment` row with `method='cash'` etc.

## 12. Billing penalty is compound, not simple — and three bugs found on review

**Question asked (Aug 2026, BillingService review pass):** "The monthly 2% penalty is
computed on each unpaid invoice's `total_amount` — which already includes last month's
penalty, and every old invoice keeps accruing each month. Is that right?"

**Answer — YES, compound, confirmed by the user:** penalty applies to the full unpaid
total, including previously-accrued penalty — the same "interest on unpaid balance" model
real PH utilities use, and the code already worked this way. No math change; the review
added a regression test (`test_penalty_compounds_on_full_carried_total`) so the behavior
is locked in and can't be "simplified" later into principal-only by accident. If the
utility ever wants simple interest, the per-invoice principal component must be tracked
explicitly — it isn't today.

**Three bugs found during the same review (all fixed + regression-tested):**

1. **Invoice number collision past 9 invoices.** `generateInvoiceNumber()` found the
   "latest" number with `orderByDesc('invoice_number')` — a *lexicographic* sort, so
   `GW-2026-00010` sorts *below* `GW-2026-00009` (`'9' > '1'`). With ≥10 invoices in a
   year, every generated number repeated `00010` → violated the DB `unique` constraint →
   the billing run died mid-way. The dev run only ever created 9 invoices, which is why
   it never fired. Fixed by deriving the sequence from `orderByDesc('id')` (creation
   order is monotonic). The old test only seeded 1 invoice; new test seeds 11.
2. **Billing window leaked into the previous month.** `periodStart` was `periodEnd - 30
   days`, so a February run (28 days) included Jan 29–31 readings. The `alreadyBilled`
   check masked it only when the January run actually happened. Fixed to the calendar
   month: `date('Y-m-01', strtotime($periodEnd))`.
3. **Run not atomic.** `BillingService::run()` wrote invoices one-by-one with no
   transaction; a mid-run failure (e.g. bug #1) left partial invoices. The loop now runs
   inside `DB::transaction()` — a failed run rolls back cleanly (idempotency remains as
   a second safety net).

## 13. Zero-usage readings: skip, don't bill ₱0.00 — and invalid input never reaches math

**Question asked (Aug 2026, BillingService robustness pass):** "A reading exists with
`cu_m_used = 0` (meter didn't move — vacant property, long vacation). Billing it creates
a ₱0.00 invoice that lingers as 0.00 'arrears' and eventually flips to 'overdue'. Should
it be billed anyway?"

**Answer — skip and report ("Zero usage — verify meter locked/closed, or bill
manually"):** a 0.00 invoice is pure noise — it can't be paid into anything, it shows up
as arrears forever, and it makes the overdue list lie. The user's real-world workflow:
for long-vacation accounts the office can **lock/close the physical meter**, so zero
consumption is expected and no invoice should exist. The report row tells the office
which account to verify. If the utility ever confirms a minimum monthly charge (see
Deferred), zero-usage handling changes to "bill the minimum" — the skip rule is
documented in `docs/insights/billing-decisions.md` for exactly that revisit.

**Related decision, same pass:** invalid math input never reaches billing math.
Unflagged negative usage (data-entry hole), a flat schedule with no rate, or a tiered
schedule with no tiers — all skip + report per account ("Non-positive usage (X cu.m.) —
investigate" / "Rate schedule misconfigured"), so the run completes and names the
accounts instead of silently billing 0.00 or negative, and instead of aborting the run.
And `--period` is now validated as a real calendar date (`checkdate`) at both the CLI
and the service level — `strtotime` silently normalizing `2026-02-31` into a wrong month
was the last "silent wrong money" path left.

**The full decision catalog** — every Billing-phase decision as
Question → Decision → Status → Code ref → Office-verify note, including the assumptions
still to confirm with the Guinobatan Waterworks office (rate value, grace days, penalty
cap, minimum charge, rate classes, 30-day buckets, cadence, zero-usage practice) — lives
in `docs/insights/billing-decisions.md`, kept in sync with this document.

---

## 14. Estimated billing for malfunctioning / abnormally high meters (deferred, not MVP)

**Question asked (Aug 2026, before the queued-billing phase):** "When a meter
malfunctions or a reading comes out negative / unusually high (e.g. a buried pipe
leak that goes unnoticed), how does the utility settle the bill? I heard that for
electricity they look at the last correct bill and settle the same amount — or take
the last three bills and settle the *highest* of them. Should we build that into
billing now, or later?"

**Answer — document it now, build it AFTER the MVP, not in this phase:**
it's a real-world practice, but this is the wrong phase to build it in, for four
reasons:

**a) The MVP is already safe for the bad-reading case.** Flagged readings (level 1/2,
including `present < previous` → negative usage) are skipped by `BillingService` and
reported for manual investigation — billing math can never see them
(`billing-decisions.md` §2). Nothing wrong gets auto-billed today, so this feature
isn't filling a correctness hole; it's automating a rare (<10/yr) manual step.

**b) It's a money rule that the office hasn't confirmed.** The source for the rule
is an *electricity* context; Guinobatan Waterworks may settle differently (average
of last 3, highest of last 3, last bill, or a one-time waiver). Money rules get
office-confirmed before they become code (see the printable checklist in
`billing-decisions.md` Part 2, new item A12). When it's built, the rule must be
**data, not hardcoded** — a config (like `PenaltyRule`) that the office's answer
feeds into.

**c) Auto-billing by estimate is risky without a human step.** A flag can mean
tampering or a wrong digit, not a malfunction. The natural integration point is the
**Admin Panel phase's manual-invoice entry UI**: when the office investigates a
flagged reading, the screen *suggests* the estimate (last correct bill / highest of
last 3), the admin confirms, and the invoice is recorded with that basis. Human
confirms; the system does the arithmetic.

**d) The "unusually high" case is a different problem.** A buried-pipe leak isn't
a meter malfunction — the meter reads *correctly*, the water was consumed, and the
dispute is a settlement/waiver negotiation, not a re-billing rule. Auto-detecting
"high but positive" readings is the deferred **leak/anomaly detection** item (Smart
Features section of ARCHITECTURE.md — compare a reading to the customer's own
trailing average); readers can already flag such readings manually (level 1) today.

**Where it lands:** `billing-decisions.md` Part 3 (Deferred) + ARCHITECTURE.md
Deferred section; office question A12 added to the Part 2 checklist; the
meter-readings G2 gap ("estimated readings") now points at this entry. Status:
documented + queued for the office, NOT scheduled into the current billing phase.



---

## 15. PayMongo webhook signature: plain HMAC, not Stripe-style timestamps — and why verified docs beat prior notes

**Question asked:** The old plan note said to parse the `PayMongo-Signature` header as timestamp parts (`t`/`te`/`li`, Stripe's scheme). Current PayMongo docs say the header is a single base64 HMAC-SHA256 of the raw request body. Which is right?

**Answer:** The docs (developer-tools webhooks key-concepts + go-live checklist + official code sample, verified 2026-08-04) describe a **plain** scheme: read `Paymongo-Signature`, compute HMAC-SHA256 of the **raw** body with the endpoint secret, compare timing-safely (`hash_equals`). No timestamp parts. The `t`/`te`/`li` idea was a Stripe-style assumption carried into ARCHITECTURE.md and has been corrected there.

**Addendum — the encoding is hex, not base64 (found the hard way):** the first implementation encoded the HMAC as base64 (assumed from memory), and *every* real test-mode delivery was rejected with 401 despite the correct secret — the webhook logs showed "signature verification failed" for all 8+ retries. The official PayMongo verification sample (docs.paymongo.com/docs/developer-tools-best-practices-1) uses `createHmac("sha256", secret).update(rawBody).digest("hex")` — **hex**. One-line fix in `verifyWebhookSignature()`. Unit tests could not catch this: they compute signatures with the same code under test, so base64-in/base64-out is self-consistent; only a genuine PayMongo delivery exposes an encoding mismatch. This is the strongest argument for the project rule of manually testing money-critical flows before going live.

**Why the plain-HMAC scheme changes how we verify:** (a) verification must run against the exact raw bytes (`$request->getContent()`) *before* any JSON parsing — any middleware or re-serialization that touches the body breaks legitimate signatures, and PayMongo's own best-practices page says exactly this; (b) there is no timestamp/tolerance window to implement, so replay-safety is purely "ack + dedupe later by event id" (the dedupe table arrives with the mark-paid item); (c) the header is spelled `Paymongo-Signature` in current docs, but older docs used `X-Paymongo-Signature` — we accept both and log which one actually arrives, because dashboard-sent payloads are ground truth.

**Ack semantics discovered from the docs:** PayMongo retries up to 12 times (exponential backoff) on any non-2xx or timeout. So the route's contract is: verify — any failure to *verify* is a 401 (PayMongo never legitimately sends that); anything that *verifies* is acknowledged with 200 `{received:true}` even if we skip it (malformed JSON, livemode mismatch, unknown event type). Never return an error for an unrecognized event — it would pile up the retry queue.

**Addendum 2 — the `t`/`te`/`li` scheme is real after all (the "plain HMAC" conclusion above was wrong, found 2026-08-04):** the paragraph above misreads PayMongo's docs. The *authoritative* format lives on docs.paymongo.com/docs/developer-tools-webhook-setup-management, which shows the header as `t=1496734173,te=<hex>,li=<hex>` and spells out the verification: sign the string `"<t>." . $rawBody` with HMAC-SHA256 (hex) using the endpoint secret, then compare against `te` for test-mode events or `li` for live-mode events. So PayMongo is Stripe-style after all — and the two prior implementations (base64 of the body, then hex of the body alone) failed every real delivery for the *same* root cause: both missed the `<t>.` prefix and the `te`/`li` selection, and the unit tests were self-consistent (the helper signed in the same wrong format). The `.env` secret (`whsk_...`, no `whsec_` prefix) matched the dashboard value byte-for-byte and was correct from the start. **Lessons:** (a) for signature schemes, find the doc page that literally *shows the header value* before writing the verifier — overview/key-concepts pages omit it; (b) add regression tests that sign with a *different* scheme (body-only, base64, wrong timestamp) to prove the verifier is strict, not just self-consistent; (c) verify the secret against the dashboard display directly instead of hypothesizing about prefixes. Timestamp freshness (replay window) remains unimplemented — optional per docs, deferred with the dedupe table.

**§17 — Store timestamps in Philippine time, not UTC (found 2026-08-05 during manual E2E test):**
every log line, `now()`, and stored `paid_at`/`processed_at` read 8 hours behind local time because
Laravel defaults `config('app.timezone')` to UTC. **Question asked:** keep engineering-standard UTC
storage and convert at the display layer, or make the app Philippine-time end to end? **Answer:**
Philippine time everywhere — this is a single-country utility, PH has no DST so there are no
daylight-savings surprises, the frontend renders stored datetimes directly (no per-field conversion
to sneak timezone bugs past us), and "the timestamp in the log/dashboard IS the timestamp in Manila"
removes a whole class of off-by-8 confusion for a non-engineering operator. Implementation:
`APP_TIMEZONE=Asia/Manila` (config/app.php now env-driven) + `phpunit.xml` for test parity, and
`PaymentService::markPaidFromWebhook` passes `config('app.timezone')` explicitly to
`Carbon::createFromTimestamp()` so we don't depend on Carbon's default-tz behavior. **Explicitly NOT
converting existing rows** — the dev DB gets wiped before production anyway. Unchanged invariant:
PayMongo event timestamps are unix (absolute instants); switching tz only changes wall-clock
rendering (02:10 UTC → 10:10 PH), never the instant — money reconciliation is unaffected.

**§18 — PayMongo removed the JS one-line popup (`PayMongo.create`), found 2026-08-05 the hard way:**
the item-2 manual test completed a card payment via a tiny page calling `PayMongo.create(clientKey).open()`
loaded from `https://js.paymongo.com/v1/paymongo.js`. During item-3's manual E2E round the same page
broke with `PayMongo.create is not a function` while its sibling `PayMongo` global existed. Grepping
the live CDN bundle showed the top-level API is now `createPaymentIntent`, `createPaymentMethod`,
`getPaymentIntent`, `elements`, and `redirectToCheckout` — the popup constructor is gone. The current
docs (quick-start, updated 2026-05-07) describe a raw-`fetch` flow instead: create the Payment Method
client-side with the **public key**, attach it with `client_key` + `return_url`, then handle
`awaiting_next_action` (3DS) redirect or `succeeded`. **Decision:** the manual-test tool
(`backend/public/pay-checkout.html`) and, by extension, the future customer-facing card form will use
the fetch flow — never the removed popup API. **Lessons:** for a browser SDK, don't trust memory or a
tutorial snippet — check the *live* bundle for the method name, and re-verify when items you built
months ago start failing in the browser; CDNs change behind stable-looking URLs. CORS on
`api.paymongo.com` is open (`Access-Control-Allow-Origin: *`, verified), so the browser flow works
from our local dev origin.

---

## 16. Why /pay blocks an already-succeeded payment, and why paymongo:reconcile never auto-credits

**Question asked (2026-08-05 hardening of Payments item 3):** when an invoice carries a stored
PayMongo payment intent that has already succeeded but the invoice was never marked paid (the
`payment.paid` webhook was missed, or is still in flight), what should `/pay` do � let the customer
pay again, or refuse?

**Answer:** refuse. **If PayMongo says a payment succeeded, the customer's money has left their
account** � our invoice record being stale does not change that. Letting them pay again would either
fail at PayMongo (you cannot attach a new payment method to a `succeeded` intent) or, worse, charge
the customer twice for one bill. `getOrCreatePaymentIntent` re-hydrates the stored intent by status:
`succeeded` ? 409 with a "being confirmed, contact support if not credited" message; unknown/expired
(4xx) ? silently replaces the stored id with a fresh intent (the old "reset the intent id by hand in
the database" workaround is now a code path); `awaiting_payment_method`/`awaiting_next_action`/
`processing` ? the customer just continues checkout. 5xx stays 5xx (nothing is mutated during an
API outage).

**But refusing forever with no escape hatch would strand real customers and real money** � that's
what `php artisan paymongo:reconcile` is for. It is deliberately **read-only and never auto-credits**
an invoice: marking an invoice paid IS an accounting action, and a nightly cron misunderstanding a
transient status is the exact failure mode a billing system cannot afford. Reconcile's job is to
*find and name* the discrepancies � an unpaid invoice whose intent succeeded ("CHARGED BUT NOT
CREDITED") and a paid PayMongo payment with no local `Payment` row ("PAYMENT WITHOUT LOCAL RECORD",
which also surfaces orphans from the "no invoice for intent" webhook path) � print them to the
console/`paymongo` log, exit non-zero, and let a human credit or refund against the PayMongo
dashboard. Money-critical flows stay manual; automation stops at detection.

**Design notes that fell out:** the "double-charge guard" (409) and the "never auto-credit" rule
(reconcile) are two halves of the same decision � block the wrong action, surface the right one,
keep the mutation human. PayMongo intents have no `failed`/`expired` terminal status worth branching
on (a failed attempt returns the intent to `awaiting_payment_method`), so the only status that
triggers the guard is `succeeded`. The reconcile window is filterable with `--days` (default 7) and
paged via the payments API's `after` cursor.

---

## 17. Payment-confirmation email: shared To-header, at-least-once, and failure visibility

**Question asked (2026-08-05 audit of Payments item 4):** the confirmation email goes to every
valid email of users with an active link to the invoice's connection � should each boarder get a
private copy, or may the email show the whole recipient list?

**Answer:** a **single message with all recipients in the `To` header**, sending separately per
recipient if privacy ever matters. Laravel's `Mail::to($array)->send()` builds ONE message whose
`To` header carries every address (`Mailable::buildRecipients`), so today the co-boarders visible to
each other is simply how it ships. Rationale: (a) boarders sharing one bill already know each other,
so the visual To-list costs nothing and avoids the false privacy promise of a fake per-recipient
loop; (b) one message means the PDF renders once, not N times; (c) it keeps the delivery path one
SMTP call to reason about. **Documented alternative:** if a future stakeholder objects to shared
addresses, switch the job to loop-and-send per recipient (N renders, N sends) — the decision lives
in the job, not the mailable.

**At-least-once is accepted.** The job allows `tries=3` + backoff; if the transport accepts a
message but the job still fails, the retry re-sends to everyone — a duplicate receipt, never a lost
one. The money is unaffected either way: the payment row + invoice state are committed **before**
the email job is even dispatched (`->afterCommit()`), so email failures can never roll money flows
back. Duplicates for a receipt are acceptable; a *lost* receipt is not.

**Permanent failures are loud, not silent.** When the job exhausts its retries, Laravel calls the
job's `failed()` hook, which logs a clear `paymongo`-channel error with invoice, invoice_number, and
payment id. Ops can therefore reconcile "payment recorded but no receipt delivered" from the log /
`failed_jobs` table.

**Testing gotcha worth remembering:** Laravel 13 has **no `array` log driver** (`LogManager` has no
`createArrayDriver`; using `driver=array` throws "Driver not supported" and silently falls back to
the emergency logger writing to `storage/logs/laravel.log`). The earlier "PAYMONGO_LOG_DRIVER=array"
test setting therefore did *not* discard logs — it just moved them to laravel.log. Fixed by pointing
the test suite at a throwaway path (`PAYMONGO_LOG_PATH=storage/logs/testing/paymongo.log`, under the
`*.log` gitignore) so `php artisan test` never touches the real `paymongo.log`. Monolog 3 also dropped
`ArrayHandler`; the in-memory test logger is `Monolog\Handler\TestHandler`.

## 19. Failed receipts surface in the admin bell; a human resends

**Question asked (2026-08-05, Phase 1 of Notifications):** a payment confirmation email that
permanently fails is only visible in ailed_jobs + the paymongo log. Is that loud enough?

**Answer + reasoning:** no. Ops only looks at logs when something else goes wrong, and a missed
receipt is a customer-comms failure that should not wait to be discovered. So the job's ailed()
hook now also writes a **Filament database notification to every admin** (danger pill with invoice
#, payment #, and the resend command). The admin bell (->databaseNotifications()) is the zero-cost
home for it: no new UI to build, no SMS dependency, and the reader is exactly the person who can act.

**The human is the fallback path.** The notification body literally contains
php artisan paymongo:send-receipt {invoice} — a new command that re-runs the job's handle() for a
paid invoice (exit 0 sent/skipped, exit 1 unknown/unpaid). We chose a command over an in-panel
"Resend" button because the action needs no UI, runs headless (scheduled/SSH), and is one step
toward the Phase-2 notifications hub without committing to a button's placement now.

**Two concurrency guardrails stay intact (verified again in tests):** the same invoice can only be
credited once (lockForUpdate in `PaymentService::markPaidFromWebhook`, plus unique
`payments.paymongo_reference` and `processed_webhook_events.event_id`), so two payments arriving
at once cannot double-credit — the loser sees `paid` and skips, exactly like the webhook path. The
resend command adds nothing new here because it only reads state; it cannot create a second payment.

**Postgres gotcha (fixed the same session):** Filament's published notifications migration uses
`->text('data')`, which breaks the bell's `data->>'format'` query on Postgres
(`text ->>` does not exist). Filament's own docs say Postgres must use `->json('data')`.
Also found: `ImportMeterReadingsPageTest` had no `RefreshDatabase`, so its committed admin user
was leaking into later tests (my new notification-count assertions caught it). Both fixed.

## 20. Offline (over-the-counter) payments: cash-only, full-amount, nearest-peso tolerance, no email

**Question asked (2026-08-06, Payments item "Record offline/manual payments in admin"):** the real
utility settles many bills offline — first-month connections, flagged-reading manual invoices, walk-in
payers. What should the admin "mark paid" flow actually record?

**Answer + reasoning (confirmed with the user):**

- **Cash only for now.** `payments.method` stays a free string; `PaymentService::OFFLINE_METHODS =
  ['cash']` is the single place to add `check` / `bank_deposit` / `remittance` later — no schema
  change, the form, badge and filter all derive from that constant. The office's instruments beyond
  cash weren't something the user could confirm ("how do we even handle a check or bank remittance?
  are they confirmable easily?"), so inventing a wider list would be guessing about money.
- **Full payment only — but the amount is what the cashier actually received.** No partial-payment
  model (deferred: it changes invoice status semantics). The guard is NOT the webhook's exact-centavos
  match: PH payers rarely split centavos, so a ₱456.56 bill is settled at "the nearest full peso" in
  the real world. The rule: amount must be **within ₱1.00 of the invoice total** — captures both
  up-rounding (457) and down-rounding (456), and still rejects a genuine partial/overpayment (≥ ₱1.00
  off) instead of silently accepting noise.
- **No receipt email on offline payments.** Portal users may not even have an email (registration
  doesn't require one); the customer is standing at the counter with a paper bill — the office's
  physical OR is the receipt. `PaymentConfirmation` stays online-only; adding an offline receipt later
  is a one-line dispatch of `SendPaymentConfirmationEmail`.
- **Audit trail mirrors meter readings:** `payments.recorded_by` (nullable FK to users) records which
  admin took the cash; payments are **create-only** in the admin UI (no edit/delete on money rows).
  Offline rows never touch `paymongo_reference` (a new nullable `reference` column holds the OR no.) —
  the unique index and `paymongo:reconcile` cross-check stay meaningful.

**Concurrency guardrail (verified in tests):** the tolerance check runs inside the same transaction
that flips the invoice to `paid` (`lockForUpdate`), so a webhook payment and an offline cash payment
racing on the same invoice cannot double-credit — the loser throws `InvoiceNotPayableException`, the
form shows a danger toast, and nothing is recorded.

## 21. Offline-payment hardening (2026-08-06 review): date at day granularity, OR length, double-collection watch

**Question asked:** during the item-5/`9031296` review, three edge cases surfaced: (a) the future-date
guard compared against `strtotime('today')` (midnight), so a *same-day* `paid_at` containing a time
component was wrongly rejected as "future"; (b) the admin form allowed references up to 200 chars while
the column holds 100 — a 101+ char OR passed validation then crashed the DB insert; (c) recording cash
offline on an invoice that still holds a PayMongo intent risks collecting money from the same bill twice.

**Answer + reasoning:**

- **(a) Date guard is day-granularity:** a payment *date* cannot be a future **day**; the exact time
  within today is irrelevant (backdating a batch is allowed, a time-suffixed same-day value is valid).
  The service now parses the value once (`Carbon::parse` in a try/catch) and compares
  `toDateString() > now()->toDateString()`. Garbage strings become a clean
  `InvalidArgumentException` ("not a valid date") instead of a Carbon exception halfway through the
  transaction. The Filament form rule mirrors the same comparison.
- **(b) The service is the single guard:** `reference` is validated `mb_strlen <= 100` in
  `PaymentService::recordOfflinePayment` (where the CLI and form both reach), and the form's
  `maxLength` was aligned back to 100 to match the column. A future-off-form direct caller gets the
  same failure the form would now show.
- **(c) Warn loudly, do not block.** Blocking offline cash because an intent string exists would be
  wrong: a stale/abandoned intent (customer peered at checkout and left) is common and self-heals on
  the next `/pay`; only a pending/succeeded intent is a real double-collection risk, and the office
  can't know that without a live PayMongo call inside the payment transaction. So recording proceeds
  but writes a `paymongo`-channel **warning** naming the invoice + intent, and the existing
  `paymongo:reconcile` Leg B ("PAYMENT WITHOUT LOCAL RECORD") is the durable backstop that surfaces
  an actual double collection for a human. If the office ever wants it stricter, the rule change is
  one condition here, not a redesign.

## 22. Dashboard metrics: what "customers" and "revenue" mean (2026-08-06)

**Question asked:** the first Admin Panel item is a dashboard with key metrics, but two terms are
ambiguous in a water-utility domain: who counts as a "customer", and what is "revenue"?
Should the count be portal user accounts? Should revenue include over-the-counter cash?

**Answer + reasoning:**

- **"Customers" = active service connections.** The utility's customer record is the *connection*
  (account + meter + registered name + barangay). Portal `User` accounts are login identities only
  � several renters can share one bill, one person can manage several bills, and most real customers
  never register at all (they pay at the office). Counting portal users would measure product
  adoption, not the customer base.
- **Revenue = every `Payment` row, by `paid_at` � PayMongo and offline cash alike.** Revenue is
  *money actually collected*, and the office records cash daily. Distinguishing by method is a
  breakdown detail, not a different metric. Month boundaries use `paid_at` (the day money arrived,
  matching the office's collection ledger) rather than `created_at` (the day the row was written �
  equivalent for webhooks, but wrong for backdated cash entries).
- **Outstanding = `sum(total_amount)` over unpaid + overdue invoices.** The amount the utility is
  owed right now, penalties included, unearned revenue excluded. Overdue bills are counted
  separately because they carry the 2%/month penalty � a collections signal, not the same as a
  merely unpaid bill.
- **Why metrics live in a Service, not the widget:** the same rule as payment logic � Filament is a
  UI consumer. The aggregates are plain query methods, unit-testable without a Livewire mount, and
  reusable by the future CRM/billing views and the customer portal later.
- **Display only in the widget:** the service returns raw floats (Postgres `numeric` aggregates
  arrive as strings), and peso formatting (`number_format()` + `?`) happens at render time � money
  math and money display never mix.

**Addendum (audit 2026-08-06):** both revenue queries are upper-bounded at `now()->endOfMonth()`.
A `paid_at` typo'd into a future month (or a row saved ahead of its collection date) used to
inflate "revenue this month" and the chart's current-month bar; now future-month rows are
invisible to revenue while same-month clock skew (webhook or locally entered timestamps a few
minutes ahead) is still counted — the stat card and the chart share one month-boundary rule.

## 23. Why online payments get a separate channel column, and why reference/recorded_by stay NULL (2026-08-06)

**Question asked:** in `/admin/payments`, PayMongo rows showed a generic "PayMongo" badge (not the
channel), an empty *Recorded By*, and an empty *Reference*. Options floated: mirror the payment id
into `reference`, write `recorded_by = some system user` for webhook rows, store card brand/last4.

**Answer + reasoning:**

- **`method` keeps its meaning; the channel is a separate column.** `payments.method` today is a
  *free-string* field whose offline entries come from `OFFLINE_METHODS` (cash) and whose online
  entry is literally `paymongo`. Overwriting `method` with `gcash`/`card` would lie to the
  "was this online or over-the-counter?" question that the whole offline-payment feature is built
  around, and it would make `paymongo:reconcile` and the webhook amount/data flows ambiguous. The
  new nullable `paymongo_source` column (channel key only, string 30) keeps "how the money
  arrived" (`method`) separate from "which specific wallet/card rail within PayMongo" (`source`),
  mirroring PayMongo's own intent→source object. Card brand/last4 are deliberately **not** stored —
  display only needs the channel, and keeping card data out of the DB reduces PCI surface.
- **`reference` means "OR / office receipt number" — a PayMongo payment has none.** `reference` was
  introduced for the offline-cash feature (the counter writes a receipt). A webhook payment has an
  internal `paymongo_reference` (the `pay_…` transaction id), but the office's *own* receipt number
  is null. Mirroring `reference = pay_…` at webhook time would (a) conflate two different concepts
  in the same column, (b) invite double-bookkeeping when an office later wants to attach a real OR
  to a web payment, and (c) need a backfill. Instead the **display** falls back
  `reference ?? paymongo_reference ?? '—'` in the table, the view form's Reference field, and the
  confirmation email. Same human answers "what do I show the customer?" without corrupting the data.
- **`recorded_by` is an audit column, not a UI convenience.** Its meaning is "which admin took the
  cash" (mirrors `meter_readings.entered_by`). PayMongo money arrives with no admin present, so the
  truthful value is NULL; a fake "system" user would break the audit trail and muddy real queries.
  The UI shows a display-only placeholder ("Recorded By: PayMongo") so admins aren't scared by the
  blank, without writing a lie to the DB.
- **Why the view page fix needed a real fix, not a label:** Filament's *native* select (non-
  searchable) renders `<option>` elements from `options()` only — `getOptionLabelUsing` is honored
  by the fancy JS select, not the native one, so a value absent from `options` renders **blank**.
  The fix widens the Method select's options with the record's actual method (labeled
  `PayMongo · GCash`) when the record exists. Found via a Livewire view test failing on a blank
  field, not by guessing.
- **No backfill.** Past PayMongo rows predate the column and get `paymongo_source = NULL`. The
  reconcile leg for historical online payments is unaffected; this is a go-forward improvement.

## 24. CRM identifiers ARE editable, and linked portal users must be told (2026-08-06)

**Question asked:** for the CRM `ServiceConnectionResource`, can an admin change `account_number` /
`meter_number` on the connection edit form? ARCHITECTURE calls the account number the key customers
use to link their portal account and the meter number the file-reader's resolution key — changing
them looked like it could orphan links or silently break CSV resolution.

**Answer + reasoning (confirmed with the user):**

- **Identifiers are editable.** Accounts get renumbered and meters get replaced in every PH
  utility's real life; a read-only identifier would force a delete/re-create, which is worse (and
  we have no create/delete in this resource). The risk isn't the edit itself — it's the *silence*
  around it.
- **The safety is a notification, not a lock:** `ConnectionLink` rows reference the connection by
  FK, so relinking is untouched by a renumber; CSV resolution just reads whatever the new value is.
  What breaks is **people**: a portal user who linked "GW-12345" to their account still expects HR,
  and a CSV with the old meter number would stop resolving. So every linked, active portal user with
  an email gets a queued `ConnectionIdentifiersChanged` mail listing *only the identifiers that
  actually changed* (old → new). The page shows a success toast with the recipient count so the admin
  knows the change wasn't silent. Snapshotting the OLD values early matters — Eloquent's
  `syncOriginal()` runs on save, so `getOriginal()` inside `afterSave` already returns the *new*
  values; the previous identifiers are captured in `beforeSave`, and the change-detection lives in
  `App\Services\ServiceConnectionService::handleIdentifierChange` (service, not the Filament page).
- **Filament v5 route gotcha:** page routes register with **no route constraint** — a default
  `/{record}` view route happily matches `create` and binds it as a non-numeric int → Postgres 500.
  Even with `canCreate()` false there is no automatic guard. Fix: the view route lives at
  `/view/{record}` (the table link points there, and any stray `/create` hits the existing
  404 behavior). A dedicated test pins `GET /admin/service-connections/create` → 404.
- **What the deep links do (and don't) do:** dashboard stat cards now link to filtered views —
  "Active customers" → service connections list pre-filtered to `status=active`, "Revenue this
  month" → payments list filtered to the current month via `paid_at` bounds. The three invoice-based
  deep links (unpaid / overdue / outstanding) are deferred until the `InvoiceResource` item exists —
  linking to a resource that doesn't exist yet would 404. URL query-state is passed as a
  rawurlencoded JSON `filters` param, matching Filament's own URL-backed filter encoding.

## 25. Identifier-change emails: snapshot recipients + dedupe (2026-08-06, hardening after review)

**Question asked:** after 8887f19 shipped, a review found three reliability gaps in the
identifier-change notification: (1) the admin toast counts recipients at save time but the queued
job re-queried links at run time, so the count could disagree with who actually got emailed;
(2) a double-save of the same identifiers emails customers twice; (3) `failed()` accessed the
connection model, which is unsafe if the record is gone by the time a job dies.

**Answer + reasoning:**
- **Recipients are now a snapshot, not a re-query.** The service resolves the recipient list once,
  passes it in the job payload, and the job emails exactly that list. Toast count, log line, and
  actual send can no longer drift. Trade-off: a user who unlinks *after* the save still receives the
  one email about the change that happened while they were linked � acceptable and deterministic.
- **Duplicates are dropped at dispatch, not at send.** The job implements `ShouldBeUnique` with a
  `uniqueId()` keyed on (connection id + changed identifiers + recipients) and a 1h lock
  (`#[UniqueFor(3600)]`). Laravel's `UniqueLock` acquires the lock in `PendingDispatch` before the
  queue write, so a second identical dispatch never even reaches the `jobs` table. Cache store is
  `database` in prod � `DatabaseStore` implements `LockProvider`, verified against the installed
  Laravel 13 source. A different edit (different old identifiers, or a new recipient) gets its own
  key and emails normally.
- **`failed()` is model-free.** The job carries `serviceConnectionId` as a plain int, so the admin
  alert + log fire even if the connection row is deleted before the job resolves (can't happen via
  the CRM � no delete � but imports/other code could).
