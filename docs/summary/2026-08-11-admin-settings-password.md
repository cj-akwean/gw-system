# 2026-08-11 — Admin profile settings, frontend password change, notification live refresh

## Goal

User requests: (1) commit the pending work, (2) admin settings with profile avatar
(same simple set as the frontend), password change and other suitable items, (3) the
same password change on the customer frontend with an almost-identical mobile-friendly
email notification. Earlier in the same session: notification hub search + live badge
refresh (10s) + refresh button.

## Files created / modified

**Notifications (committed as `1092ed4`)**
| File | Action | What |
|---|---|---|
| `AdminPanelProvider.php` | modified | `databaseNotificationsPolling('10s')` |
| `app/Livewire/AdminDatabaseNotifications.php` | modified | `render()` dispatches `refresh-sidebar` (sidebar badge follows the bell's 10s poll) |
| `app/Filament/Pages/NotificationHub.php` | modified | table `poll('10s')`, Refresh header action, title/body search (wildcard-escaped) |
| `tests/Feature/NotificationHubTest.php`, `AdminDatabaseNotificationsTest.php` | modified | +8 tests |
| `AGENTS.md`, `ARCHITECTURE.md`, `docs/insights/implementation-notes.md` | modified | queue-worker note (admin notifications are queued!), checklist + §7 |

**User's own pending work (committed as `0fbe0ce`)** — `PayMongoAutoCreditCommand`,
`HealthController`, `InvoiceReconcileController`, `routes/api.php`/`console.php`,
`payment-method.tsx`, `api.ts`. Sanity-checked (php -l + route:list) before commit.

**Admin settings (uncommitted)**
| File | Action | What |
|---|---|---|
| `backend/public/avatars/avatar-{1..4}.svg` | new | static exports of the frontend's 4 avatar components (React mask ids `:r111:` etc. fixed to `m1`-`m4`) |
| `app/Models/User.php` | modified | implements `HasAvatar` → `getFilamentAvatarUrl()` (svg when avatar_id set, null → initials) |
| `app/Filament/Pages/EditProfile.php` | new | extends Filament's EditProfile; avatar Select (allowHtml, in:1-4) prepended; `afterSave()` queues PasswordChanged email when a new password was set |
| `AdminPanelProvider.php` | modified | `->profile(EditProfile::class)` |
| `app/Mail/PasswordChanged.php` + `password-changed-html/text.blade.php` | new | queued mailable + mobile-friendly hybrid template (600px, media query, bulletproof button, Arial, no images) |
| `app/Http/Controllers/Api/ChangePasswordController.php` + `ChangePasswordRequest.php` | new | `POST /api/password` — current-password check (generic 422), `Password::min(8)` + confirmed, revokes all OTHER tokens (current session kept), queues email; `throttle:10,1,password-change` |
| `routes/api.php` | modified | password route |
| `frontend/src/lib/api.ts` + `api.test.ts` | modified | `changePasswordApi()` + tests |
| `frontend/src/app/settings/page.tsx` + `page.test.tsx` | modified | Security section (current/new/confirm, client-side length+match checks, busy state, server errors) + 5 tests |
| `tests/Feature/ChangePasswordTest.php` (8 tests), `AdminEditProfileTest.php` (8 tests) | new | endpoint + profile page coverage |

## Bugs found & fixed

1. **`Sanctum::actingAs()` uses a transient (non-persisted) token** — token-survival
   assertions after "revoke other tokens" were meaningless; fixed by switching those
   tests to `withToken()` with a real persisted token.
2. **Native `minLength` blocked our friendly validation** — the input's `minLength={8}`
   stopped form submission before the React handler ran, so the "must be at least 8
   characters" message could never appear (test caught it). Removed the attribute; JS +
   server both enforce.
3. **Frontend build worker crash** (exit 3221225501) — known node heap issue on this
   machine; `NODE_OPTIONS=--max-old-space-size=4096` fixes it (noted in docs).
4. **Filament profile page progressive disclosure** — confirm/current-password fields
   are `->visible()` until a new password is typed (built-in); verified live.
5. **Admin credentials restored after live test** — changed password/avatar during
   browser verification, restored `admin123` / null avatar; demo queued emails purged.

## Test results

- Backend: `ChangePasswordTest` + `AdminEditProfileTest` + `ProfileUpdateApiTest` +
  `RateLimitTest` + `NotificationHubTest` + `AdminDatabaseNotificationsTest` = **72/72**
- Frontend: `api.test.ts` + `settings/page.test.tsx` = **36/36**; `npm run build` green
- Browser-verified: admin `/admin/profile` (avatar picker w/ images, save, password
  change → hash changed + email queued); frontend `/settings` Security section
  (password change → "Password updated.", session kept); password-changed email at
  390px — no horizontal scroll, fluid 362px container

## Known gaps / next step

- Admin profile email change goes through Filament's default path (no email
  verification configured — applies immediately); frontend has no email change by user
  decision.
- Next step: docs summary is this file; commit the admin-settings batch (2 commits
  above are already in; this batch is uncommitted).

## Git commit hash

`1092ed4` (notifications), `0fbe0ce` (auto-credit batch); admin-settings batch uncommitted.
