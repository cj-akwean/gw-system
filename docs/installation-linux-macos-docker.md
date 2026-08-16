# Installing GW-System on Linux / WSL2 / macOS / Docker

The project's *official* setup is Windows-first: `setup.bat` + `start.bat` automate
everything there, and the manual Windows recipe lives in `docs/manual-setup.md`. This
doc covers the alternatives — **Linux**, **WSL2** (Windows Subsystem for Linux),
**macOS**, and a **Docker**-based workflow.

Nothing about the app itself is Windows-only. The backend (Laravel) and frontend
(Next.js) are plain PHP + Node projects; only the *install scripts* are Windows-bound.
So every other platform just installs the same four tools by hand and then runs the
same setup steps.

---

## Requirements (same on every platform)

| Tool | Version | Why |
|---|---|---|
| PHP | **8.5** | Runs the Laravel backend |
| Composer | 2.8+ | Installs PHP packages |
| Node.js + npm | Node 24+ / npm 11+ | Runs the Next.js frontend |
| PostgreSQL | 18 | The database |

After installing the tools, confirm all four from a terminal:

```bash
php -v          # must say 8.5.x
composer -V
node -v         # v24.x
npm -v
psql --version  # psql (PostgreSQL) 18.x
```

---

## Steps that are identical everywhere

Once PHP, Composer, Node, and PostgreSQL are installed and the two databases exist
(covered per-platform below), the rest is the same on Linux, WSL2, and macOS. These are
the README's "Option B" steps with shell commands instead of PowerShell.

### 1. Backend — dependencies + database schema

```bash
cd backend
cp .env.example .env            # copy the env template into a real .env
composer install                # installs all PHP packages (may take minutes)
php artisan key:generate        # fills in APP_KEY in .env (required!)
php artisan migrate             # creates the tables in the database
php artisan db:seed             # demo data + the two logins (optional but handy)
```

> **Set the DB block first.** `backend/.env.example` ships with `DB_CONNECTION=sqlite`
> selected. The app uses PostgreSQL — edit `backend/.env` before migrating:

```ini
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=gw_system
DB_USERNAME=postgres
DB_PASSWORD=postgres
```

### 2. Frontend — dependencies + env file

```bash
cd frontend
cp .env.example .env.local      # copy the env template into a real .env.local
npm install                     # installs all JS packages (may take minutes)
```

`frontend/.env.local` needs `NEXT_PUBLIC_API_URL=http://127.0.0.1:8000` (the default in
the template is fine) plus your PayMongo public key if you want to test payments.

### 3. Run the app (3 terminals)

```bash
# Terminal 1 — Backend (API + admin panel)
cd backend
php artisan serve

# Terminal 2 — Frontend (customer portal)
cd frontend
npm run dev

# Terminal 3 — Queue worker (background jobs: emails, PDFs, billing)
cd backend
php artisan queue:work --tries=3
```

### 4. What to open

| Service | URL | Login |
|---|---|---|
| Laravel API | http://127.0.0.1:8000 | — (JSON only) |
| Filament Admin | http://127.0.0.1:8000/admin | `admin@gwsystem.com` / `admin123` |
| Next.js Frontend | http://localhost:3000 | `test@example.com` / `password` |

> **No SSL fix needed on Linux/macOS.** The README's "cURL error 60" section is a
> Windows-only problem (Windows ships no CA bundle). Linux and macOS ship one, so
> PayMongo / Resend / Mailtrap calls work out of the box.

---

## Linux (Ubuntu 24.04 / Debian)

Ubuntu 24.04 ships PHP 8.3 and PostgreSQL 16 — **not** 8.5 / 18 — so add two third-party
repos. (Ubuntu 26.04 ships PHP 8.5 and PostgreSQL 18 in the default archive; see the
note at the end.)

### PHP 8.5 (Ondřej Surý's PPA)

```bash
sudo apt update
sudo apt install -y software-properties-common apt-transport-https
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.5-cli php8.5-common php8.5-pgsql php8.5-curl \
  php8.5-mbstring php8.5-xml php8.5-intl php8.5-zip php8.5-bcmath php8.5-sqlite3
```

(`php8.5-common` includes OPcache; there is no separate `php8.5-opcache` package.)

### Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### Node.js 24 (NodeSource)

```bash
curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -
sudo apt install -y nodejs
```

### PostgreSQL 18 (official PGDG repo)

```bash
sudo apt install -y postgresql-common ca-certificates
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh   # adds the repo
sudo apt update
sudo apt install -y postgresql-18
```

### Start Postgres and create the databases

PostgreSQL runs as a `systemd` service (auto-starts on boot):

```bash
sudo systemctl start postgresql
```

The default install uses *peer* auth over the local socket: the `postgres` superuser
has no password yet. Set one, then create the two databases over TCP with that password:

```bash
sudo -u postgres psql -c "ALTER USER postgres WITH PASSWORD 'postgres';"
PGPASSWORD=postgres psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE gw_system;"
PGPASSWORD=postgres psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE gw_system_testing;"
```

Verify everything, then continue with the [identical steps](#steps-that-are-identical-everywhere):

```bash
php -v && composer -V && node -v && npm -v && psql --version
```

> **Ubuntu 26.04:** PHP 8.5 and PostgreSQL 18 are in the default archives — skip the
> PPA/PGDG repo setup and just `sudo apt install php8.5-cli php8.5-common php8.5-pgsql
> php8.5-curl php8.5-mbstring php8.5-xml php8.5-intl php8.5-zip php8.5-bcmath
> php8.5-sqlite3 postgresql-18`.
>
> **Debian 12/13:** same recipe, but the PHP packages come from the `packages.sury.org`
> repo instead of a PPA — see https://packages.sury.org/ (grab the signing key + add the
> `deb` line for your release). Composer, NodeSource, and PGDG steps are unchanged.

---

## WSL2 (Windows Subsystem for Linux)

WSL2 gives you a real Linux distribution on Windows, so the whole
[Linux](#linux-ubuntu-2404--debian) section applies — just install Linux first.

### 1. Install WSL2 + Ubuntu

From an **admin** PowerShell on Windows:

```powershell
wsl --install          # installs WSL2 + Ubuntu; reboot when prompted
```

After the reboot, finish Ubuntu's first-run setup (username + password). From then on,
run **all Linux commands inside the WSL Ubuntu terminal**, not PowerShell.

### 2. Differences from plain Linux

| Thing | What's different |
|---|---|
| **Ports** | WSL2 auto-forwards localhost, so your **Windows browser** reaches the app at `http://localhost:3000` / `http://127.0.0.1:8000` — no extra config. The frontend's `NEXT_PUBLIC_API_URL=http://127.0.0.1:8000` keeps working because both servers run inside WSL. |
| **Where to put the code** | Clone/copy the repo **inside the WSL filesystem** (e.g. `~/gw-system`) for full speed. Running it from `\mnt\c` or `\mnt\d` (your Windows `C:`/`D:` drives) *works* but is much slower (`npm install`, `node_modules`, file watching). |
| **systemd** | Recent Ubuntu WSL images start with systemd enabled, so `sudo systemctl start postgresql` works. If you get `sudo: systemctl: command not found`, start services the old way: `sudo service postgresql start`. |
| **Port collisions** | WSL and Windows can run the *same* port without conflict. If something on Windows already holds 5432/8000/3000, WSL's own services are unaffected. |

Then follow the Linux install above (PHP 8.5 → Composer → Node 24 → PostgreSQL 18 →
databases), and continue with the [identical steps](#steps-that-are-identical-everywhere).

---

## macOS

Everything installs through **Homebrew**. Homebrew's `php` formula is PHP 8.5 and ships
the `pgsql` / `pdo_pgsql` drivers compiled in — no extra PHP extensions to install.

### 1. Install Homebrew (if needed)

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

### 2. Install the tools

```bash
brew install php            # php@8.5, includes pgsql + pdo_pgsql
brew install composer
brew install node@24        # keg-only — see PATH note below
brew install postgresql@18  # keg-only
```

`node@24` and `postgresql@18` are **keg-only** (not linked into `/usr/local/bin` or
`/opt/homebrew/bin` by default). On Apple Silicon, add to `~/.zshrc`:

```bash
echo 'export PATH="/opt/homebrew/opt/node@24/bin:/opt/homebrew/opt/postgresql@18/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc
```

(Intel Macs use `/usr/local/opt/...` instead of `/opt/homebrew/opt/...`.)

### 3. Set up a Postgres cluster that matches the project's login

Homebrew initializes a default cluster whose superuser is **your macOS username** with
no password. To match the project's `postgres` / `postgres` login (used in
`backend/.env`), replace that cluster:

```bash
# Apple Silicon:
/opt/homebrew/opt/postgresql@18/bin/initdb -D /opt/homebrew/var/postgresql@18 \
  -U postgres -W --locale=en_US.UTF-8 -E UTF8 -A scram-sha-256
# Intel:
/usr/local/opt/postgresql@18/bin/initdb -D /usr/local/var/postgresql@18 \
  -U postgres -W --locale=en_US.UTF-8 -E UTF8 -A scram-sha-256
```

`-W` prompts you for the superuser password — set it to **`postgres`**.

> **Prefer your own username instead?** Skip the `initdb` above and keep Homebrew's
> default cluster. Then set `DB_USERNAME=<your macOS username>` and
> `DB_PASSWORD=` (empty) in `backend/.env` instead of the `postgres`/`postgres` block.

### 4. Start Postgres and create the databases

```bash
brew services start postgresql@18     # runs in the background; stop with `brew services stop postgresql@18`
PGPASSWORD=postgres createdb -U postgres -h 127.0.0.1 gw_system
PGPASSWORD=postgres createdb -U postgres -h 127.0.0.1 gw_system_testing
```

Verify the tools, then continue with the
[identical steps](#steps-that-are-identical-everywhere).

---

## Docker

> **The repo ships no Docker files yet** — `setup.bat`/`start.bat` are Windows-native
> and there is no `Dockerfile`/`docker-compose.yml`. These are the containerized
> approaches that work today without changing the repo.

### Approach A — PostgreSQL in Docker, everything else on the host (recommended)

The most common need is "I don't want to install PostgreSQL natively." Run **just the
database** in a container; the backend and frontend run on the host exactly as above.
This needs no repo changes at all.

1. Install **Docker Desktop** (macOS/Windows) or **Docker Engine + compose plugin**
   (Linux).
2. Run PostgreSQL 18:

   ```bash
   docker run -d --name gw-postgres \
     -e POSTGRES_USER=postgres \
     -e POSTGRES_PASSWORD=postgres \
     -p 5432:5432 \
     -v gw_pgdata:/var/lib/postgresql \
     postgres:18
   ```

3. Create the two databases:

   ```bash
   docker exec -it gw-postgres psql -U postgres -c "CREATE DATABASE gw_system;"
   docker exec -it gw-postgres psql -U postgres -c "CREATE DATABASE gw_system_testing;"
   ```

4. Install PHP 8.5 / Composer / Node 24 on the host (any platform section above) and
   follow the [identical steps](#steps-that-are-identical-everywhere) — `backend/.env`
   already points at `127.0.0.1:5432`.

5. Stop / start the DB anytime:

   ```bash
   docker stop gw-postgres && docker start gw-postgres
   ```

> **⚠️ `postgres:18` changed its data path.** From v18 the official image stores data at
> `/var/lib/postgresql/18/docker` (not `/var/lib/postgresql/data`), so the volume must
> be mounted at **`/var/lib/postgresql`** — mounting at the old `/data` path silently
> writes nothing to your volume and you lose all data when the container is recreated.

### Approach B — Laravel Sail (Laravel-native, but it adds files to the repo)

Laravel's official Docker dev environment. Unlike Approach A, this **writes files into
the repo** (`backend/docker-compose.yml`, `backend/Dockerfile`, `.dockerignore`), so
treat it as a repo change — and it covers the *backend* only; the Next.js frontend still
runs on the host.

```bash
cd backend
composer require laravel/sail --dev
php artisan sail:install
# backend/.env, set the DB block to Sail's defaults:
#   DB_CONNECTION=pgsql  DB_HOST=pgsql  DB_PORT=5432
#   DB_DATABASE=gw_system  DB_USERNAME=sail  DB_PASSWORD=password
./vendor/bin/sail up -d        # boots Postgres + the backend inside containers
./vendor/bin/sail artisan migrate --seed
```

The queue worker runs inside Sail too — start it with
`./vendor/bin/sail artisan queue:work --tries=3`. The backend is then at
`http://localhost` (Sail maps it to port 80). Create the test database once:

```bash
./vendor/bin/sail exec pgsql psql -U sail -c "CREATE DATABASE gw_system_testing;"
```

> **Gotcha:** inside Sail the backend reaches Postgres at hostname `pgsql`, not
> `127.0.0.1`. When you later run `php artisan` on the host instead, flip `DB_HOST`
> back to `127.0.0.1` and use the `postgres` user/password. Don't mix the two.

### What's not covered

A full "everything in containers" stack (backend + frontend + DB + queue worker behind
one `docker compose up`) is not in this repo. If you need it, it would be a real
infrastructure contribution — recommend opening a separate issue/task to add the
Dockerfiles and compose file.

---

## Platform differences at a glance

| Platform | Package manager | Install Postgres with | Start Postgres | Create DBs with |
|---|---|---|---|---|
| Linux (Debian/Ubuntu) | `apt` | `postgresql-18` (PGDG repo) | `sudo systemctl start postgresql` | `sudo -u postgres psql` |
| WSL2 | `apt` | same as Linux | `sudo systemctl ...` (or `sudo service ...`) | same as Linux |
| macOS | `brew` | `brew install postgresql@18` | `brew services start postgresql@18` | `createdb` |
| Docker | — | `postgres:18` image | `docker start gw-postgres` | `docker exec ... psql` |

---

## Troubleshooting (platform-specific)

| Symptom | Fix |
|---|---|
| `password authentication failed for user "postgres"` (Linux) | You never set the password — run `sudo -u postgres psql -c "ALTER USER postgres WITH PASSWORD 'postgres';"` |
| `sudo: systemctl: command not found` (WSL2) | systemd isn't running in the distro — use `sudo service postgresql start` |
| `php` runs the wrong version (Linux) | `which php`; if multiple versions are installed, `sudo update-alternatives --config php` |
| `pdo_pgsql` "driver not found" (macOS) | Homebrew PHP compiles pgsql in — verify with `php -m \| grep pgsql`. If missing, `brew install libpq && brew reinstall php` |
| Postgres starts fresh / data gone (Docker) | You mounted at `/var/lib/postgresql/data` — remount the named volume at `/var/lib/postgresql` (v18 path change) |
| Ports 3000/8000/5432 busy | Check with `lsof -i :<port>` (Linux/macOS) or `netstat -ano \| findstr <port>` (Windows); kill the process or run `php artisan serve --port=8001` |
| `curl error 60` on Linux/macOS | Shouldn't happen — Linux/macOS ship CA bundles. If it does, check `openssl.cafile` in `php.ini` (this error is a Windows-only problem by default) |