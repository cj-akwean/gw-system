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

## PayMongo — relevant docs only (don't guess from memory)

PayMongo's API surface is broad. Before implementing or modifying anything payment-related, check the specific page for what's being built. Entry points: platform overview / what PayMongo covers — https://docs.paymongo.com/docs/get-started-what-is-paymongo ; API reference (endpoints, schemas) — https://docs.paymongo.com/reference/getting-started-with-your-api ; full docs index — https://docs.paymongo.com/docs/.

> **Doc trap (learned 2026-08-04):** `developer-tools-best-practices-1`'s webhook verification sample is **wrong** (signs the body alone, compares against the whole header). The real signature format — `t=<ts>,te=<test sig>,li=<live sig>`, signed string `"<t>.<rawBody>"`, compare `te`/`li` — is documented only on `developer-tools-webhook-setup-management`. The old `developers.paymongo.com` site is retired (dead links). When a docs page links to a dead page for a critical detail, search for the literal header value/format and cross-check the official SDK (`paymongo/paymongo-node`) — never trust a code sample alone.

## Admin Credentials

| Email | Password | Role | Access |
|---|---|---|---|
| admin@gwsystem.com | admin123 | Super Admin | `/admin` (Filament dashboard) |
| test@example.com | password | Portal Test User | customer portal `/dashboard` (linked to connection #1 with paid/overdue/unpaid bills) |

> Use `admin@gwsystem.com` / `admin123` to log into the Filament admin panel; `test@example.com` / `password` for the customer portal. Both seeded via `php artisan db:seed` (updateOrCreate — re-seeding resets these credentials).

## Key Decisions

| Decision | Choice |
|---|---|
| Database | Postgres (dev = prod) |
| Payments | PayMongo (one-off billing, not subscriptions) |
| Sanctum mode | Bearer tokens (not SPA cookies) |
| Queue driver | database (no extra service needed) |
| Invoices | Generate PDF → email on payment; no permanent storage |
| Meter readings | CSV import + manual entry via Filament |
| Knowledge graph | `.graphifyignore` excludes vendor; graph = project code only |
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
- **Windows PHP SSL prerequisite:** outbound HTTPS from PHP fails with `cURL error 60`
  until the CA bundle is configured (PayMongo calls return 502). Already set up on this
  machine; see README.md → "PHP HTTPS / SSL" for the recipe, diagnosis, and fix. If a
  PayMongo/Resend call ever 502s, check `storage/logs/laravel.log` for `cURL error 60`
  BEFORE touching service code.
- **Queue worker:** `php artisan queue:work` in a second terminal — required for
  payment-confirmation emails, identifier-change notifications, SMS, PDFs, and billing runs.
  Without a worker, queued jobs sit silently in the `jobs` table forever. Kill with Ctrl+C
  when done.

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
- **Sanity check**: `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit` (or `php -l` on changed files) + `php artisan route:list` if routes changed. **Why not plain `php artisan test`:** the full suite OOMs at the default 128M `memory_limit` (dompdf font parsing in the email tests → PHPUnit reports "Premature end of PHP process"), and `artisan test` does NOT propagate `-d` to PHPUnit. The direct phpunit binary with the `-d` flag is the reliable invocation (282/282 green).
- If no test suite exists for the touched area, say so explicitly

### 6. Commit cadence — NEVER commit without approval, suggestion only at the bottom
- NEVER run `git commit`/`push`/`amend` unless the user explicitly asks. No exceptions, no "I'll commit this for you", even for small fixes
- Commit suggestions appear ONLY in the last line/block at the bottom of a response — never mid-task
- When only small/unverified changes remain → advise WAITING: small bugs stay undetected until a real scenario exercises them; don't rush a commit of code that hasn't been run/verified
- Suggest a commit only after a **meaningfully complete, working** checklist item
- Not after every small edit, and not after multiple features bundled together

### 7. Use the knowledge graph, not just grep
- Before editing shared code (models, services, anything with callers), run `graphify query "<term>"` first to see what depends on it — the graph knows relationships grep can't
- If `graphify query` returns nothing useful, fall back to grep — don't force it
- After any significant structural change (new models, renamed services, moved files), re-run `graphify . --update` to refresh the graph incrementally — no `--no-viz`, the graph is small enough to render
- `backend/vendor` and `backend/public/js` are excluded via `.graphifyignore`; the graph covers project code only, so vendor questions go to grep
- If `--update` ever reports thousands of changed files, the previous run's `graphify-out/manifest.json` baseline is stale — verify it covers the full corpus before re-extracting (don't blindly re-extract vendor churn)
- Treat `graphify-out/GRAPH_REPORT.md` as a sanity check against `ARCHITECTURE.md` — if they disagree about what exists, flag it

### 8. Documentation workflow (product-decisions.md + session summaries)

This project keeps several kinds of running documentation, in addition to ARCHITECTURE.md's checklist. All are living documents — append to them, don't rewrite history.

**`docs/insights/implementation-notes.md`** — the detail archive for ARCHITECTURE.md's Implementation Status checklist (same sections, `§N` items). When a checklist item is completed, its full implementation note goes HERE as a new `###` item — ARCHITECTURE.md bullets stay one-liners with `(details: … → §Section-N)` pointers.

**`docs/insights/product-decisions.md`** — the "why" behind non-obvious choices. Append a new dated section whenever:
- A design question comes up with real trade-offs (not "what did I build" but "why this way and not the obvious alternative")
- A domain-specific rule gets discovered or decided (e.g. flag-vs-block logic, PH utility conventions, real-world constraints found from research)
- A past decision gets revisited or corrected

Format: mirror the existing structure — **Question asked** → **Answer + reasoning**, plain language, written so someone outside the project (a reviewer, a future collaborator, a pitch audience) understands the "why" without reading code.

**`docs/summary/YYYY-MM-DD-topic.md`** — one file per work session. Write this:
- At the end of any session that completed real work (not every tiny edit — one meaningful session = one file)
- Proactively **before** hitting the ~80% context/compaction threshold (Rule #2), so nothing gets lost to summarization
- Must include: goal, files created/modified, bugs found & fixed (with root cause, not just symptom), test results (what was actually verified vs. not), known gaps / next step, git commit hash

**At the start of every session**, read the most recent `docs/summary/*.md` file and skim `docs/insights/product-decisions.md` — not just `ARCHITECTURE.md`/`AGENTS.md` — before continuing work. The summary carries context ARCHITECTURE.md's checklist alone doesn't (what was tried, what broke, what's still unverified).

**Never let these silently go stale.** If a session ends without a summary being written, that's a rule violation worth flagging to the user, not skipping quietly.

### 9. Batch verification — test once, near the end
- Do NOT run the test suite after every file edit. Implement the whole checklist item
  first, then run the full suite once, close to the end (right before Rule 5 pre-commit
  checks), so all breakages surface in a single pass instead of per edit. Per-edit runs
  cost more time than they save and test half-built code.
- Applies to both suites: `npm test` (frontend Vitest) and the phpunit invocation in
  Rule 5 (backend).
- Exceptions: tests themselves are the task; the user explicitly asks for a
  mid-implementation check.
- Finished-but-unverified work mid-session is fine — record it as unverified in the
  session summary (Rule 8) and let the end-of-implementation batch run catch it.

### 10. Responsive rule — mobile-first, verified at tablet AND desktop
- All customer-facing frontend work is **mobile-first**: design the phone layout first, then
  extend it for tablet (≥768px / `md:`) and desktop (≥1024px / `lg:`). A screen is not done
  until it has been laid out and visually verified at all three widths.
- Never hard-cap the page at a phone width (`max-w-md` alone) — use fluid containers that
  widen (`max-w-md md:max-w-4xl lg:max-w-5xl`) and multi-column grids at larger breakpoints
  (e.g. the bills list is 1-col → 2-col (`sm:`) → 3-col (`lg:`)). The old phone-column-with-
  dead-space dashboard was the anti-pattern that spawned this rule.
- Money flows (payment screens) follow the same rule: stacked single column on mobile,
  two-column (summary + QR/actions) at `lg:`.
- Every frontend session's manual verification must include a viewport pass: phone (~390px),
  tablet (~768px), desktop (~1280px), and the result noted in the session summary.
