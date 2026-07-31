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
