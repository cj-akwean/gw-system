# GW-System Architecture

## Directory Structure

```
gw-system/
  frontend/     ← Next.js + shadcn (static marketing + customer portal)
  backend/      ← Laravel 13 + Postgres (API + Filament admin)
  ARCHITECTURE.md
  AGENTS.md
```

## Tech Stack

| Layer | Technology |
|---|---|
| Customer-facing UI | Next.js 16 + React 19 + shadcn/ui + Tailwind v4 |
| Admin Panel | Filament (PHP/Livewire) |
| Backend Framework | Laravel 13 |
| Database | Postgres (both dev & prod — Docker/Laravel Sail for local) |
| API Auth | Laravel Sanctum — Bearer token mode (not SPA cookies) |
| Payments | **PayMongo** (one-off payments, not subscriptions) |
| Invoices | Generate PDF via barryvdh/laravel-dompdf, email to customer |
| Queue | Laravel Queues (database driver) for billing runs, SMS, PDF generation |
| File Storage | None permanent — PDFs generated in-memory and emailed; regenerate from DB on demand |
| Email (prod) | Resend |
| Email (dev/test) | Mailtrap |
| SMS | Semaphore (PH) or Twilio (global) |
| Exports / Reports | Laravel Excel |

## Architecture

```
Frontend (Next.js)                Backend (Laravel)
────────────────────              ──────────────────
Marketing pages  ──API calls──▶   Regular API routes
Customer portal                    (auth, billing, payments, readings)
  (Bearer token auth)               │
Admin Panel (Filament) ───────────── same DB, same models
(CRM, dashboard, billing mgmt)      (Livewire — no API needed)
                                    │
Payment Gateway (PayMongo) ───────── webhook → marks invoice paid
```

- **Next.js frontend** stays as-is for marketing pages. Customer portal features (pay bills, view usage) added later via API calls using Sanctum Bearer tokens.
- **Filament admin** provides CRM, dashboard, billing management at `/admin` — separate auth guard from API, rate-limited differently.
- **PayMongo webhooks** notify Laravel when a payment succeeds → invoice marked paid, PDF generated and emailed.
- **Business logic** lives in `App\Services\*` Service classes, never in Filament Resources.

## Database

- Postgres for both dev and prod (avoid SQLite dev / MySQL prod surprises)
- Use **Laravel Sail** (Docker) or local Postgres install for development
- Postgres is stricter about constraints — catches bugs earlier for a billing system where data integrity matters

## Queue & Background Jobs

- Billing runs, SMS blasts, PDF generation, bulk exports → Laravel Queues
- Start with `database` driver (no extra service needed)
- Prevents request timeouts during bulk operations

## Meter Readings

- **CSV import** (bulk upload via Filament) + **manual entry** (Filament form)
- No hardware/automated reading integration for now
- Audit trail for every reading entry (who entered it, when)

## Security

- `/admin` uses separate Filament auth guard — not reachable by API tokens
- Public API routes rate-limited; admin routes have separate limits
- Sanctum Bearer tokens for Next.js (not SPA cookie mode — simpler with separate domains)

## Invoices & Storage

- PDF generated on payment confirmation via dompdf
- Emailed to customer as attachment — no permanent file storage
- If "download past invoices" feature needed later, regenerate PDF from DB data (bill amount, date, customer) — no files to host

## Payment Webhook Handling

- PayMongo webhook route must be **idempotent**: check if the invoice is already marked paid before processing, since webhook providers can retry/send duplicate notifications
- Verify webhook signature before trusting the payload

## Testing, Monitoring, Backups

- **Backups**: enable automatic daily DB backups on whatever host you use
- **Testing**: manual testing of money-critical flows (payment, invoice generation) before going live
- **Error tracking**: optional — Sentry free tier later if needed

## Development

```bash
# Start Laravel (API + admin)
cd backend
php artisan serve

# Start Next.js (customer UI)
cd frontend
npm run dev
```

- Laravel serves on `http://127.0.0.1:8000`
- Next.js serves on `http://localhost:3000`
- CORS is configured to allow Next.js to call Laravel APIs (Bearer token auth)

---

## Implementation Status

> Keep this checklist in sync with the actual codebase. Check an item only when it is genuinely working end-to-end (not stubbed). Update in the same commit that completes the item.

### Foundation
- [ ] Laravel 13 backend scaffolded, Postgres connected (dev via Sail)
- [ ] Next.js frontend scaffolded (marketing pages)
- [ ] CORS configured between frontend and backend
- [ ] `.env.example` files present for both apps (no real secrets committed)

### Auth
- [ ] Sanctum installed, Bearer token issuance on login working
- [ ] Token revocation / logout working
- [ ] Filament admin auth guard set up separately from API guard

### Core Data Models
- [ ] Customer model + migration
- [ ] Meter/Account model + migration
- [ ] Meter Reading model + migration (with audit fields: entered_by, entered_at)
- [ ] Invoice/Bill model + migration
- [ ] Payment model + migration

### Meter Readings
- [ ] Manual entry form in Filament
- [ ] CSV bulk import in Filament
- [ ] Validation on import (reject bad rows, show errors)

### Billing
- [ ] Billing calculation logic in `App\Services\BillingService`
- [ ] Billing run as a queued job (not synchronous)
- [ ] Invoice PDF generation (dompdf)

### Payments
- [ ] PayMongo integration (create payment intent/checkout)
- [ ] PayMongo webhook route (signature verified, idempotent)
- [ ] Invoice marked paid on webhook confirmation
- [ ] Invoice PDF emailed to customer on payment confirmation

### Admin Panel (Filament)
- [ ] Dashboard with key metrics (customers, unpaid invoices, revenue)
- [ ] CRM views (customer list, detail, edit)
- [ ] Billing management views

### Notifications
- [ ] Email sending working (Mailtrap in dev, Resend in prod)
- [ ] SMS notifications wired up (Semaphore/Twilio) — optional, later

### Infra / Ops
- [ ] Queue worker running (database driver)
- [ ] Automatic daily DB backups enabled on host
- [ ] Basic rate limiting on public API routes
