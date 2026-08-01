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

