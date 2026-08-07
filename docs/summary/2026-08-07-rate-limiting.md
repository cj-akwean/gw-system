# 2026-08-07 — Basic rate limiting on public API routes

## Goal
Close the Infra/Ops checklist item "Basic rate limiting on public API routes". Before this
change every `/api/*` route had a limit except `POST /api/paymongo/webhook` (fully public, no
auth, unlimited) and the authenticated portal routes (`GET /user`, `POST /logout`,
`GET/POST/DELETE /api/links`).

## Design (scope + limits, confirmed with user)
| Route | Limit | Key |
|---|---|---|
| `POST /api/paymongo/webhook` | `throttle:60,1` | IP (anonymous) |
| `POST /api/login` (unchanged) | `throttle:10,1` | IP (anonymous) |
| `GET /api/user`, `POST /api/logout` | `throttle:30,1` | user id |
| `GET/POST/DELETE /api/links` | `throttle:30,1` | user id |
| `POST /api/invoices/{invoice}/pay` (unchanged) | `throttle:20,1` | user id |

Inline throttles only — matches the existing codebase idiom; 429 already renders as JSON on
`/api/*` via the existing `shouldRenderJsonWhen` in `bootstrap/app.php`, with
`X-RateLimit-*` / `Retry-After` headers from the framework. Hardcoded values (no env knob →
can't misconfigure to 0/negative).

## Files created / modified
| File | What |
|---|---|
| `backend/routes/api.php` | Webhook throttle added; `user`/`logout` throttled; links routes throttled; pay + login unchanged |
| `backend/tests/Feature/RateLimitTest.php` (new) | 5 tests (below) |
| `ARCHITECTURE.md` | Checkbox checked; Security/Infra note + deferred proxy-trust caveat |

## Bug caught in the plan → design change (root cause)
**Two inline throttles on one route share a cache key → counter double-hits → early 429.**
Initial plan put `throttle:30,1` on the `auth:sanctum` group *and* kept `throttle:20,1` on the
pay route inside it. Both inline throttles resolve the same cache key for an authenticated
user (the user id), so **each request incremented that key twice** (once per middleware).
`InvoicePaymentEndpointTest::test_pay_route_is_rate_limited` then 429'd inside its 20-OK loop
(~request 11, since 2 hits/request × … trips the 20-cap). Fix: **exactly one throttle per
route** — the group has no throttle; each links route carries its own `throttle:30,1`. This
also means portal routes share one 30/min per-user bucket (combined ceiling), which is the
intended "30/min across the user's API activity" semantic.

## Test results
- `RateLimitTest` 5/5: webhook 60 requests all 200 → 61st 429 (JSON msg +
  `X-RateLimit-Limit: 60` + `X-RateLimit-Remaining: 0` + `Retry-After`); webhook ≤limit stays
  200; webhook per-IP isolation (2nd IP still 200 at 61); links 30×200 → 31st 429; per-user
  isolation (user B unaffected when user A exhausted).
- Focused suite (RateLimitTest + CustomerAuthTest + InvoicePaymentEndpointTest +
  ConnectionLinkApiTest + ResendReceiptControllerTest + PayMongoWebhookTest + AuthTest):
  **62/62 passed**.
- Full suite: **468/468 passed, 1902 assertions**. `php -l` clean on both touched PHP files;
  `php artisan route:list --path=api` shows 8 routes incl. all throttles.

## Known gaps / deferred
- **Reverse-proxy trust:** behind nginx/FPM every request reports `127.0.0.1` as client IP
  unless trusted-proxies is configured at deploy time → limits become a whole-site ceiling
  (still a valid DoS cap, just not per-IP). Documented in ARCHITECTURE.md; the exact proxy
  step belongs to the Infra runbook at go-live.
- `graphify . --update` not needed (routes + tests only; no shared-code structural change).

## Next step (recommended)
Already beyond the checklist's eastern top — the checked box is the deliverable. Suggested
next unchecked item: an Infra answer (host selection → apply `deploy/linux/*` + trusted
proxies) or begin Customer Portal UI wiring (consumes these throttled endpoints).
Verify margins: keep webhook at 60/min/IP (retries + bursts are idempotent and rare).

## Commit
Not committed (needs explicit approval). Bundle: `backend/routes/api.php` +
`backend/tests/Feature/RateLimitTest.php` + ARCHITECTURE.md checkbox + this summary.