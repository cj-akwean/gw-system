# Session Summary — 2026-08-05 (Payment item 3 hardening: dedupe fix, double-charge guard, reconcile)

> Companion to `2026-08-05-paymongo-webhook-job.md` (item 3's original implementation). This
> session = independent deep review of item 3 ("Invoice marked paid on webhook confirmation")
> + hardening the three real findings. NOT committed yet (commit suggestion at the bottom).

## Goal
Deep-review Payments checklist item 3 against edge cases (real money flows), then fix what was
lacking: (1) a genuine job bug on duplicate non-paid events, (2) `/pay` returning dead intents
forever + a double-charge hole when a stored intent already succeeded, (3) no safety net for
"customer charged, invoice never credited".

## Files created
- `backend/app/Exceptions/PaymentAlreadyCompletedException.php` — thrown when an invoice's stored
  intent already succeeded but the invoice isn't credited; `/pay` catches → 409 (never pay twice).
- `backend/app/Console/Commands/PayMongoReconcileCommand.php` — `php artisan paymongo:reconcile`
  (read-only): Leg A = unpaid/overdue invoices with a stored intent that is `succeeded` →
  "CHARGED BUT NOT CREDITED"; Leg B = `GET /v1/payments` (status=paid, `created_at` window, paged
  by `after`) cross-checked vs `payments.paymongo_reference` → "PAYMENT WITHOUT LOCAL RECORD".
  5xx/network → "UNCHECKED" (never a false finding). Exit 1 on any finding; never mutates state.
- `backend/tests/Feature/PayMongoReconcileCommandTest.php` — 7 tests (clean, charged-not-credited,
  paid-invoice-ignored, orphan payment, known-payment-ignored, intent 5xx unchecked, list 5xx
  unchecked). Uses `withoutMockingConsoleOutput()` + `Artisan::call` — **not** `expectsOutputToContain`
  chained multiple times: the console-output mock only evaluates the FIRST matching expectation per
  write call (Mockery), so a second substring on the same line can never match (found the hard way,
  see bugs below).

## Files modified
- `backend/app/Jobs/ProcessPayMongoWebhook.php` — non-`payment.paid` branch now catches
  `UniqueConstraintViolationException` on the dedupe insert (log "already processed" + return
  instead of failing the job); `recordProcessedEvent()` wraps the insert in `DB::transaction()` so
  the Postgres connection recovers (SQLSTATE 25P02) before the catch runs — same pattern as the
  earlier paid-path fix.
- `backend/app/Services/PayMongoService.php` — `getOrCreatePaymentIntent()` re-hydrates the stored
  intent via new `getStoredPaymentIntent()`: `succeeded` → throw `PaymentAlreadyCompletedException`
  (409, double-charge guard); 4xx or ownership-mismatch metadata → return null → fresh intent is
  created (stale-intent self-heal — the manual "null the id" workaround is now a code path);
  `awaiting_*`/`processing` → return client key as-is; 5xx → throw, stored id untouched. New
  `getPaymentIntentStatus()` (null on 4xx) + `listPaidPayments(from, to)` (paginated, for the
  reconcile command).
- `backend/app/Http/Controllers/Api/InvoicePaymentController.php` — catches
  `PaymentAlreadyCompletedException` → 409 "A payment for this invoice already went through and is
  being confirmed. If it is not credited shortly, please contact support."
- `backend/tests/Feature/ProcessPayMongoWebhookTest.php` — +3 tests (duplicate `payment.failed`,
  duplicate `payment_intent.succeeded`, non-paid duplicate after a successful `payment.paid`).
- `backend/tests/Feature/PayMongoServiceTest.php` — +8 tests (succeeded-stored → exception & no
  POST; 404-stored → fresh intent; ownership mismatch → fresh intent; 5xx-stored → throw & id kept;
  `getPaymentIntentStatus` ×3; `listPaidPayments` pagination/after-cursor, 5xx throw, malformed
  throw).
- `backend/tests/Feature/InvoicePaymentEndpointTest.php` — +1 test (succeeded stored intent →
  409, no POST, status stays unpaid).
- `ARCHITECTURE.md` — item 2 sub-bullet: stale-intent self-heal + succeeded-409; item 3 sub-bullet:
  non-paid dedupe fix; new item-3 sub-bullet: `paymongo:reconcile` description.
- `docs/insights/product-decisions.md` — §16: why `/pay` blocks already-succeeded payments and why
  reconcile never auto-credits.
- `docs/manual-tests/paymongo-payment-e2e.md` — the manual "reset the intent id" step is now only
  needed for the succeeded-intent case; stale intents self-heal.

## Bugs found & fixed (with root cause)
1. **Duplicate non-paid events failed the job** (`ProcessPayMongoWebhook`): the `payment.failed` /
   `payment_intent.succeeded` / `payment_intent.awaiting_payment_method` branch wrote the dedupe row
   with no catch; a dashboard Resend or a batch redelivery after the app was briefly down threw
   `UniqueConstraintViolationException` → retried 3× (guaranteed to fail — the row committed on
   attempt 1) → `failed_jobs` churn. Paid branch already handled this; the fix extends the same
   semantics. First fix attempt caught the exception but Postgres 25P02 aborted the implicit
   transaction; final fix wraps the insert in `DB::transaction()` (rollback recovers the
   connection) then catches.
2. **`/pay` returned stored dead intents forever + double-charge hole**: a stored intent that 404s
   (or one that already succeeded while the webhook was missed) was handed back to the customer
   every time — the manual E2E workaround was to null the DB field by hand. Now: 4xx → fresh intent
   automatically; `succeeded` → 409, because PayMongo having taken the money means the customer must
   NOT pay again (would fail at PayMongo or double-charge). The succeeded case is what reconcile
   exists to surface.
3. **Test-harness trap (not app code): `expectsOutputToContain` × 2 on one line never matches the
   second substring.** Mockery consumes a `doWrite` call with the first matching expectation only,
   so substrings in the same output chunk aren't all checked. Command tests switched to
   `withoutMockingConsoleOutput()` + `Artisan::call()` + explicit `assertStringContainsString`.

## Test results
- `php artisan test`: **166/166 pass, 489 assertions** (was 145/145, 437 → +21 tests, +52
  assertions). `php -l` clean on all touched files. `php artisan list` shows `paymongo:reconcile`.
- No new routes added; `route:list --path=paymongo` unchanged (`POST api/paymongo/webhook`).
- Pint not yet run on the new files (verification step below).

## Known gaps / deferred (unchanged from prior sessions)
- Reconcile is a manual/daily-cron command — scheduling lands with the Infra phase cron.
- Live-mode webhook delivery still untested (test-mode E2E passed last session; same code path).
- `t`-timestamp freshness / replay window still deferred.
- `processed_webhook_events` / `billing_runs` tables still have no retention job (fine at this scale).
- "no invoice for intent" payments are surfaced by reconcile Leg B but still require a human to
  match them to a customer (no auto-matching — correct, see product-decisions §16).

## Next recommended step (unchecked item)
Payments item 4: `App\Jobs\SendPaymentConfirmationEmail` (ShouldQueue) dispatched from inside
`PaymentService::markPaidFromWebhook` after marking paid, reusing `PdfService::generate()` as the
attachment; Mailtrap dev / Resend prod.
