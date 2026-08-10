# GW-System — Manual Windows Fresh Install (reference)

> The original hand-run install sequence, kept for reference (verified 2026-08-09).
> Prefer the automated `setup.bat` + `start.bat` at the project root — these steps
> are only needed on machines where the scripts can't be used.
>
> What the automated scripts do: install PHP 8.5 + Composer + Node.js + PostgreSQL 18
> via winget, create `php.ini` with extensions + memory limit, download the SSL CA
> bundle (cURL error 60 fix), add paths, create the databases, run `composer install`,
> migrate and seed.

## Step 1: Install PHP via winget

```powershell
winget install --id=PHP.PHP.8.5
```

**Problem:** Winget installs PHP but does **not** create an active `php.ini`. Only
`php.ini-development` and `php.ini-production` templates exist. PHP loads with zero
extensions and no memory limit override. This breaks everything downstream.

**Fix — create php.ini and enable required extensions:**

```powershell
$phpDir = "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe"

# Copy the dev template
Copy-Item "$phpDir\php.ini-development" "$phpDir\php.ini"
```

Then edit `$phpDir\php.ini` and make these changes:

1. **Set extension_dir** (around line 758) — the default is commented out and PHP
   defaults to `C:\php\ext` which does not exist:
   ```ini
   extension_dir = "C:\Users\<YOU>\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\ext"
   ```

2. **Enable these extensions** (remove the `;` from each):
   ```ini
   extension=curl
   extension=openssl
   extension=pdo_pgsql
   extension=pgsql
   extension=mbstring
   extension=fileinfo
   extension=intl
   extension=zip
   ```

3. **Set memory limit** (around line 428):
   ```ini
   memory_limit = 512M
   ```
   (dompdf OOMs at the default 128M when generating PDFs.)

**Verify:**
```powershell
php -m | findstr curl        # should print "curl"
php -m | findstr pdo_pgsql   # should print "pdo_pgsql"
php -r "echo ini_get('memory_limit');"  # should print "512M"
```

## Step 2: PHP SSL / HTTPS (cURL error 60 fix)

Windows does not ship a CA bundle. Without it, every outbound HTTPS call from PHP
(PayMongo, Resend, Mailtrap) fails with `cURL error 60`.

```powershell
$phpDir = "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe"

# Download the CA bundle
Invoke-WebRequest -Uri "https://curl.se/ca/cacert.pem" -OutFile "$phpDir\cacert.pem"
```

Then in the same `php.ini`, add these under their respective sections:

```ini
[curl]
curl.cainfo = "C:\Users\<YOU>\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\cacert.pem"

[openssl]
openssl.cafile= "C:\Users\<YOU>\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\cacert.pem"
```

**Verify:**
```powershell
php -r "echo ini_get('curl.cainfo'), PHP_EOL;"
# must print the .pem path (not empty)
```

## Step 3: Install Composer

The standard Composer installer uses PHP's `copy()` function which requires HTTPS —
which does not work until Step 2 is done. Do Steps 1-2 first, then:

```powershell
New-Item -ItemType Directory -Path "C:\composer" -Force
Invoke-WebRequest -Uri "https://getcomposer.org/installer" -OutFile "C:\composer\composer-setup.php"
php "C:\composer\composer-setup.php" --install-dir=C:\composer --filename=composer
Remove-Item "C:\composer\composer-setup.php"
```

Add `C:\composer` to your user PATH:
```powershell
[Environment]::SetEnvironmentVariable("Path", [Environment]::GetEnvironmentVariable("Path", "User") + ";C:\composer", "User")
```

**Verify:** open a **new** terminal, then:
```powershell
composer --version
```

## Step 4: Install Node.js

```powershell
winget install --id=OpenJS.NodeJS.LTS
```

Or use nvm (if already installed):
```powershell
nvm install 24
nvm use 24
```

## Step 5: Install PostgreSQL

```powershell
winget install --id=PostgreSQL.PostgreSQL.18
```

When prompted, set the superuser password to `postgres` (matching the `.env` config).

**Problem:** The PostgreSQL `bin` directory is not added to PATH automatically. You
must add it manually or `psql` will not be found.

```powershell
# Add to user PATH (run in PowerShell)
[Environment]::SetEnvironmentVariable("Path", [Environment]::GetEnvironmentVariable("Path", "User") + ";C:\Program Files\PostgreSQL\18\bin", "User")
```

Then open a **new** terminal and create the databases:
```powershell
$env:PGPASSWORD = "postgres"
psql -U postgres -c "CREATE DATABASE gw_system;"
psql -U postgres -c "CREATE DATABASE gw_system_testing;"
```

## Step 6: Project setup

```powershell
cd backend
composer install
composer setup    # runs: key:generate, migrate, npm install, npm run build
```

## Step 7: Seed data + create admin

```powershell
cd backend
php artisan db:seed
```

This creates:
- Admin user: `admin@gwsystem.com` / `admin123`
- Test user: `test@example.com` / `password`
- Barangays, rate schedules, penalty rules, demo portal data

## Step 8: Run the app (3 terminals)

```powershell
# Terminal 1 — Backend
cd backend
php artisan serve

# Terminal 2 — Frontend
cd frontend
npm run dev

# Terminal 3 — Queue worker
cd backend
php artisan queue:work --tries=3
```

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `could not find driver` on `artisan migrate` | `pdo_pgsql` extension not enabled | Uncomment `extension=pdo_pgsql` in php.ini |
| `Call to undefined function mb_strimwidth()` | `mbstring` extension not enabled | Uncomment `extension=mbstring` in php.ini |
| `cURL error 60` in `laravel.log` | No CA bundle configured | Follow Step 2 above |
| `memory_limit` errors on PDF generation | Default 128M too low | Set `memory_limit = 512M` in php.ini |
| `psql: command not found` | PostgreSQL not in PATH | Add `C:\Program Files\PostgreSQL\18\bin` to PATH |
| `composer: command not found` | Composer not in PATH | Add `C:\composer` to PATH or use `php C:\composer\composer` |
| Extension loads but PHP says "module not found" | `extension_dir` points to wrong path | Set `extension_dir` to the WinGet `ext` folder in php.ini |
