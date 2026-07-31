# Session Summary — 2026-07-30 (Core Data Models)

## Goal
Implement all 9 Core Data Models for GW-System: Barangay, PortalUser (extend User), ServiceConnection, ConnectionLink, MeterReading, RateSchedule + RateTier, PenaltyRule, Invoice, and Payment.

## Models Created

| # | Model | Migration | Factory | Seeder | API |
|---|---|---|---|---|---|
| 1 | `Barangay` | ✅ | ✅ | ✅ 15 barangays | — |
| 2 | `User` (PortalUser) | ✅ +phone | ✅ updated | — | — |
| 3 | `ServiceConnection` | ✅ | ✅ | — | — |
| 4 | `ConnectionLink` | ✅ | ✅ | — | ✅ 3 routes |
| 5 | `MeterReading` | ✅ | ✅ | — | — |
| 6 | `RateSchedule` + `RateTier` | ✅ | ✅ | — | — |
| 7 | `PenaltyRule` | ✅ | ✅ | ✅ 2%/month | — |
| 8 | `Invoice` | ✅ | ✅ | — | — |
| 9 | `Payment` | ✅ | ✅ | — | — |

## Backend — Laravel

### Models (9)
- `app/Models/Barangay.php`
- `app/Models/ServiceConnection.php`
- `app/Models/ConnectionLink.php`
- `app/Models/MeterReading.php`
- `app/Models/RateSchedule.php`
- `app/Models/RateTier.php`
- `app/Models/PenaltyRule.php`
- `app/Models/Invoice.php`
- `app/Models/Payment.php`

### Migrations (10)
- `2026_07_30_000001_create_barangays_table.php`
- `2026_07_30_000002_add_phone_to_users_table.php`
- `2026_07_30_000003_create_service_connections_table.php`
- `2026_07_30_000004_create_connection_links_table.php`
- `2026_07_30_000005_create_meter_readings_table.php`
- `2026_07_30_000006_create_rate_schedules_table.php`
- `2026_07_30_000007_create_rate_tiers_table.php`
- `2026_07_30_000008_create_penalty_rules_table.php`
- `2026_07_30_000009_create_invoices_table.php`
- `2026_07_30_000010_create_payments_table.php`

### Factories (8)
- `database/factories/BarangayFactory.php`
- `database/factories/ServiceConnectionFactory.php`
- `database/factories/ConnectionLinkFactory.php`
- `database/factories/MeterReadingFactory.php`
- `database/factories/RateScheduleFactory.php`
- `database/factories/PenaltyRuleFactory.php`
- `database/factories/InvoiceFactory.php`
- `database/factories/PaymentFactory.php`

### Seeders (2)
- `database/seeders/BarangaySeeder.php` — 15 Guinobatan barangays
- `database/seeders/PenaltyRuleSeeder.php` — 2%/month, 15-day grace, 60-day disconnection

### Controller (1)
- `app/Http/Controllers/Api/ConnectionLinkController.php` — index, store, destroy

### Form Request (1)
- `app/Http/Requests/LinkConnectionRequest.php`

### Files Modified (4)
- `app/Models/User.php` — added `phone` to fillable, `connectionLinks()` and `enteredReadings()` relationships
- `database/factories/UserFactory.php` — added optional phone number
- `routes/api.php` — added 3 link routes under `auth:sanctum`
- `database/seeders/DatabaseSeeder.php` — added BarangaySeeder + PenaltyRuleSeeder calls

## Self-Serve Linking API

| Endpoint | Method | Auth | What it does |
|---|---|---|---|
| `/api/links` | GET | sanctum | List user's active links with connection details |
| `/api/links` | POST | sanctum | Link by `account_number` + `meter_number` (uses `updateOrCreate`) |
| `/api/links/{link}` | DELETE | sanctum | Revoke a link (soft — sets status + `unlinked_at`) |

## Key Design Decisions
- **PortalUser** = existing `User` model (no separate model needed)
- **ConnectionLink** uses `updateOrCreate` for idempotent linking (re-activates revoked links)
- **Invoice numbering** format: `GW-YYYY-XXXXX`
- **RateSchedule** supports both `flat` and `tiered` types via `RateTier` child table
- **No Filament resources yet** — Admin Panel phase follows later

## Git Commits This Session

| Hash | Message |
|---|---|
| `dac9a9f` | feat: add Barangay model, migration, factory, and seeder (15 Guinobatan barangays) |
| `4770e08` | feat: add phone field to users table (extend User as PortalUser) |
| `9645bef` | feat: add ServiceConnection model, migration, and factory |
| `0ddcf9f` | feat: add ConnectionLink model, self-serve API routes (link by account+meter number) |
| `80f9f57` | feat: add MeterReading model, migration, and factory |
| `ba30211` | feat: add RateSchedule and RateTier models, migrations, and factory |
| `236048c` | feat: add PenaltyRule model, migration, factory, and seeder (2%/month) |
| `b1a243e` | feat: add Invoice model, migration, and factory |
| `a8c25ce` | feat: add Payment model, migration, and factory |

## ARCHITECTURE.md Checklist Updated
- [x] Barangay model + migration (seed 15 real Guinobatan barangays)
- [x] PortalUser (extended User with phone column)
- [x] ServiceConnection model + migration
- [x] ConnectionLink model + migration + self-serve API
- [x] MeterReading model + migration
- [x] RateSchedule + RateTier models + migration
- [x] PenaltyRule model + migration (seed 2%/month)
- [x] Invoice model + migration
- [x] Payment model + migration

## Graphify
- Knowledge graph updated with `--code-only` flag (7396 code files re-extracted)

## Next Step
Meter Readings — Manual entry form in Filament
