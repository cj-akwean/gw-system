# 2026-08-06 — Planning: admin reports, receipt payer identity, customer registration (doc-only)

## Goal
User raised three asks: (1) admin-downloadable CSV reports ("any kinds of reports"), (2) emailed
receipt should name whose bill was paid / who paid (proofing), (3) the system never collected
applicant registration details or an application flow (real-world "apply for water → issued meter
no. + account no."). This session was **plan + record only** — no feature code was written; the
work is captured in the checklist for one-item-per-session execution later.

## Key decisions (user-confirmed)
- Exports: **CSV only**, via maatwebsite/excel (already installed for imports) — no new package.
  Filament v5 core has no built-in exporter; the plugin path needs queue + on-disk files, breaking
  the no-queue/no-permanent-storage rules.
- Export targets: payments + service connections now; invoices ship with the pending
  `InvoiceResource` item.
- Registration: full applicant field set (phone, email, gender, birthdate, civil_status,
  occupation), `pending` status, **enable create** on the CRM, plus a CSV import page
  (ImportMeterReadings-style) so existing registrants can be onboarded — user explicitly asked
  "how to migrate/import existing users".
- Payer identity: **no `paid_by` column** — the PayMongo `payment.paid` webhook already carries
  `attributes.billing` (name/email/phone of who paid at checkout). Store as nullable
  `payer_name/email/phone` on payments. Caveat recorded: it's the channel payer, not necessarily
  the linked portal user — which is exactly the right proofing record.

## Files created / modified
- `ARCHITECTURE.md` — new unchecked item under Payments (payer identity on receipt) + new sections
  "Admin Reports / Exports" (3 items) and "Customer Registration (Admin)" (3 items), all marked
  *(planned 2026-08-06)*
- `docs/insights/product-decisions.md` — appended §26 (rationale: exporter choice, payer capture,
  registration approach, deferred portal-user-paid-by logging)

## Bugs found & fixed
- N/A (doc-only). Note: **pre-existing encoding corruption** in product-decisions.md — literal
  U+FFFD replacement chars in older sections (§22, §25 tails), visible as `�` when rendered. Not
  touched (out of scope, risk of churn); flag for a future cleanup pass.

## Test results
- No code changed → no tests run. Docs verified by re-reading edited sections.

## Known gaps / next step
- Execution order (one item per session): Phase A = payer capture + receipt rows (Payments item);
  Phase B = payments + connections CSV exports; Phase C = registration schema/form/create, then
  the import page.
- No commit made (needs explicit user approval).
