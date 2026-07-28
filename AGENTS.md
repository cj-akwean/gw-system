# GW-System — Project Context

## Architecture

```
gw-system/
  frontend/     ← Next.js + shadcn (customer-facing UI)
  backend/      ← Laravel 13 + Postgres (API + Filament admin)
```

- **Next.js frontend** handles marketing pages + customer portal (pay bills, view usage) — Bearer token auth via Sanctum
- **Laravel backend** serves JSON API + Filament admin at `/admin` (CRM, dashboard, billing)
- Customer portal features are added to Next.js via API calls to Laravel
- PayMongo webhooks notify Laravel on successful payment → invoice marked paid, PDF emailed

## Backend Rules

- Keep business logic in **Service classes** (`App\Services\*`), NOT in Filament Resources
- Models, migrations, and services are portable — Filament is just a UI consumer
- Use **Sanctum Bearer token auth** for Next.js API calls (not SPA cookie mode)
- **Postgres** for both dev and prod (use Docker/Sail for local — avoid SQLite/MySQL mismatch bugs)
- Use **Laravel Queues** (database driver) for billing runs, SMS, PDF generation — never synchronously in a request
- `/admin` uses separate auth guard — not reachable by API tokens; rate limits differ between public and admin routes
- **No permanent file storage** — PDFs generated in-memory and emailed; regenerate from DB on demand
- **PayMongo webhook must be idempotent** — check if invoice is already paid before processing; verify signature

## Key Decisions

| Decision | Choice |
|---|---|
| Database | Postgres (dev = prod) |
| Payments | PayMongo (one-off billing, not subscriptions) |
| Sanctum mode | Bearer tokens (not SPA cookies) |
| Queue driver | database (no extra service needed) |
| Invoices | Generate PDF → email on payment; no permanent storage |
| Meter readings | CSV import + manual entry via Filament |
| Testing | Manual for money-critical flows |
| Backups | Auto daily DB backups on host |

## Key Packages

| Need | Package |
|---|---|
| Admin CRM dashboard | Filament (free) |
| Billing / Invoicing | Custom models + PDF (barryvdh/laravel-dompdf) |
| Payments | PayMongo |
| API Auth | Laravel Sanctum |
| Exports / Reports | Laravel Excel |
| SMS Notifications | Semaphore (PH) or Twilio (global) |
| Email (prod) | Resend |
| Email (dev/test) | Mailtrap |

## Development

```bash
# Laravel (API + admin)
cd backend && php artisan serve

# Next.js (customer UI)
cd frontend && npm run dev
```

- Laravel: `http://127.0.0.1:8000`
- Next.js: `http://localhost:3000`

---

## Workflow Rules

### 1. One step at a time
- Never implement more than **one** checklist item per work session
- Before writing code, state the single task and confirm it matches an unchecked item in `ARCHITECTURE.md`'s Implementation Status

### 2. Context / compaction aware
- If token usage nears the limit, proactively warn and suggest wrapping up + committing
- After compaction, re-read `AGENTS.md` and the Implementation Status section before continuing

### 3. Verify before and after
- Before starting: scan actual codebase against the Implementation Status checklist — don't trust checkboxes alone
- After finishing: update the relevant checkbox in `ARCHITECTURE.md` in the same commit
- Never introduce a package/pattern not reflected in `ARCHITECTURE.md` without flagging first

### 4. Always guide next step
After finishing a task, tell the user:
1. What was changed (plain language)
2. What to manually check/click/test to confirm it works
3. The single next recommended step from the unchecked items

### 5. Pre-commit checks
Run before suggesting a commit:
- **Secret scan**: check staged files for API keys, tokens, passwords. Confirm `.env` is in `.gitignore` and NOT staged
- **Sanity check**: `php artisan test` (or `php -l` on changed files) + `php artisan route:list` if routes changed
- If no test suite exists for the touched area, say so explicitly

### 6. Commit cadence
- Suggest a commit after each **meaningfully complete, working** checklist item
- Not after every small edit, and not after multiple features bundled together
