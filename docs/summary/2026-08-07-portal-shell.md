# 2026-08-07 — Customer portal shell

## Goal

Build the post-login customer portal shell: dashboard, unpaid-bills list, authentication
redirects, and no payment controls yet.

## Files created or modified

- Added `backend/app/Services/PortalBillsService.php` and
  `backend/app/Http/Controllers/Api/InvoiceController.php`.
- Added authenticated `GET /api/invoices`, limited to unpaid/overdue invoices on active
  connection links and throttled per route.
- Added `backend/tests/Feature/InvoiceListingApiTest.php`.
- Added the Next.js `/dashboard` route and portal header, bill cards, loading/error/empty
  states, session-expiry redirect, and peso formatting.
- Updated `/auth` to redirect authenticated users to `/dashboard`.
- Added Vitest 4 + Testing Library with a single-worker, sequential `happy-dom` setup.
- Updated `ARCHITECTURE.md` to mark the portal-shell checklist item complete.

## Bugs found and fixed

- The first Vitest mock returned a new router object on every render. Because the bills
  effect depended on the router, this caused an infinite render loop and multi-gigabyte
  memory growth. The mock now returns one stable router object.
- Vitest is explicitly run with `vitest run`, `maxWorkers: 1`, and no file parallelism so
  this small frontend suite cannot leave a watch process or worker fan-out running.

## Verification

- Frontend tests: 18 passed / 18 tests (`npm test`).
- Backend full suite: 486 passed / 486 tests, 2,199 assertions.
- Frontend production build passed and generated `/dashboard`.
- PHP syntax checks and `php artisan route:list --path=api/invoices` passed.
- `npm run lint` still reports pre-existing React hook/style errors in existing files;
  no new dashboard test failure remains.

## Known gaps / next step

- No payment button or payment screen was added by design; the next portal checklist item
  is Payment Method.
- Changes are not committed yet.
