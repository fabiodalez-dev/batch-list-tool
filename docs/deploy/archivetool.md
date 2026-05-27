# Deploy procedure — archivetool.eu

Production deployment of the Batch List Tool (Laravel 13 + Filament 5) on
**archivetool.eu**, a cPanel shared-hosting account at VHosting Italia
(`cpanel19.vhosting-it.com`, CloudLinux EL8).

This document covers:

1. Server facts and conventions
2. Prerequisites (one-time)
3. First-time bootstrap (manual, one-shot)
4. Continuous deploys (automated via GitHub Actions)
5. Rollback
6. Troubleshooting

> All file paths in this document refer to the production server unless
> explicitly prefixed with `(local)`.

---

## 1. Server facts

| Item             | Value                                                          |
|------------------|----------------------------------------------------------------|
| SSH alias        | `archivetool`                                                  |
| Host             | `cpanel19.vhosting-it.com`                                     |
| Port             | `22`                                                           |
| User             | `archivet`                                                     |
| Home             | `/home/archivet/`                                              |
| cPanel web root  | `/home/archivet/public_html/` (cannot be changed in shared hosting) |
| Laravel app dir  | `/home/archivet/laravel-app/` (lives OUTSIDE web root)         |
| PHP CLI          | `/usr/local/bin/php` (8.3.31)                                  |
| Composer         | `/usr/local/bin/composer` (2.9.8)                              |
| Git              | `/usr/local/cpanel/3rdparty/lib/path-bin/git` (2.48.2)         |
| Node / npm       | NOT installed — Filament assets must be pre-built and committed |
| Database         | MySQL `archivet_nra` (4.7 MB), user `archivet_nrauser`         |
| TLS              | terminated at the cPanel reverse proxy (X-Forwarded-Proto)     |

The cPanel web root **cannot be moved** to a subdirectory like
`public_html/public/`, so the deploy uses a "bootstrap shim" pattern: the
real Laravel project lives in `/home/archivet/laravel-app/`, and a small
`public_html/index.php` (`deploy/cpanel-bootstrap-index.php`) re-routes
requests into `laravel-app/public/index.php`. This keeps `vendor/`,
`storage/`, and `.env` outside the document root.

---

## 2. Prerequisites

### 2.1. Required GitHub repository secrets

Configure under **Settings -> Secrets and variables -> Actions** in the
`fabiodalez-dev/batch-list-tool` repository:

| Secret                   | Value                                                | Purpose                                  |
|--------------------------|------------------------------------------------------|------------------------------------------|
| `SSH_PRIVATE_KEY_DEPLOY` | private OpenSSH key (no passphrase)                  | authenticate the deploy workflow as `archivet` |
| `SSH_HOST`               | `cpanel19.vhosting-it.com`                           | host the workflow SSHs into             |
| `SSH_USER`               | `archivet`                                           | remote user                              |
| `DEPLOY_PATH`            | `/home/archivet/laravel-app`                         | absolute path of the Laravel project on the server |
| `SLACK_WEBHOOK_URL`      | *(optional)* Slack incoming webhook URL              | post deploy notifications               |

The matching public key must be installed in
`/home/archivet/.ssh/authorized_keys` on the server.

### 2.2. Generate a dedicated deploy SSH key

On any trusted workstation (NOT the production server):

```bash
ssh-keygen -t ed25519 -C "github-actions-archivetool-deploy" \
           -f ~/.ssh/archivetool_deploy -N ""
```

- Upload `~/.ssh/archivetool_deploy.pub` to cPanel via
  **Security -> SSH Access -> Manage SSH Keys -> Import Key**, then click
  **Authorize**. (Or paste the public key into
  `/home/archivet/.ssh/authorized_keys` directly if SSH is already set up.)
- Paste the **private** key contents into the GitHub secret
  `SSH_PRIVATE_KEY_DEPLOY`.
- Delete the private key from the workstation:
  `shred -u ~/.ssh/archivetool_deploy`.

### 2.3. Database password

The MySQL user `archivet_nrauser` already exists. Reset its password via
cPanel **MySQL Databases -> Current Users** and keep the value at hand for
section 3.4.

---

## 3. First-time bootstrap (MANUAL)

> Run this exactly **once**. Subsequent updates are handled by the GitHub
> Actions workflow (section 4). Every command below runs as the `archivet`
> user from your workstation (`ssh archivetool '...'`).

### 3.1. Backup the legacy raw-PHP app

`public_html/` currently contains an OLD raw-PHP MVC app
(`composer.json`, `app/`, `config/`, `database/`, `.env` dated 2026-05-25).
Move it aside before touching anything:

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet
    if [[ -d public_html && ! -L public_html ]]; then
        mv public_html "public_html.legacy_$(date -u +%Y-%m-%d)"
    fi
    mkdir -p public_html
'
```

### 3.2. Backup the existing database

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet
    BACKUP="archivet_nra_backup_$(date -u +%Y-%m-%d).sql"
    # Reads ~/.my.cnf for the password; if absent, supply --password=...
    mysqldump --defaults-file=~/.my.cnf --single-transaction \
              --routines --triggers --events \
              archivet_nra > "${BACKUP}"
    gzip "${BACKUP}"
    ls -lh "${BACKUP}.gz"
'
```

> If `~/.my.cnf` does not exist, create it with:
> ```ini
> [client]
> user=archivet_nrauser
> password=<the password from section 2.3>
> host=localhost
> ```
> Then `chmod 600 ~/.my.cnf`.

### 3.3. Clone the repository

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet
    git clone --branch main \
              https://github.com/fabiodalez-dev/batch-list-tool.git \
              laravel-app
    cd laravel-app
    /usr/local/bin/composer install --no-dev --optimize-autoloader \
                                    --no-interaction --prefer-dist
'
```

### 3.4. Populate `.env`

Generate `/home/archivet/laravel-app/.env` from `.env.example` and edit:

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet/laravel-app
    cp .env.example .env
    chmod 600 .env
'
```

Then open `.env` with `nano` over SSH and set at least:

```dotenv
APP_NAME="NRA Batch List"
APP_ENV=production
APP_KEY=                           # filled by `php artisan key:generate`
APP_DEBUG=false
APP_URL=https://archivetool.eu
APP_TIMEZONE=Europe/Malta

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=archivet_nra
DB_USERNAME=archivet_nrauser
DB_PASSWORD=<from section 2.3>

SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

CACHE_STORE=file
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

MAIL_MAILER=smtp
MAIL_HOST=smtp.archivetool.eu
MAIL_PORT=465
MAIL_USERNAME=noreply@archivetool.eu
MAIL_PASSWORD=<smtp password>
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=noreply@archivetool.eu
MAIL_FROM_NAME="${APP_NAME}"

# Disable any optional service that is not configured on the server
BROADCAST_CONNECTION=log
PULSE_ENABLED=false
TELESCOPE_ENABLED=false
```

Generate the application key:

```bash
ssh archivetool '
    cd /home/archivet/laravel-app
    /usr/local/bin/php artisan key:generate --force
'
```

### 3.5. Migrate the schema and seed initial data

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet/laravel-app
    /usr/local/bin/php artisan migrate --force
    /usr/local/bin/php artisan shield:generate --all --panel=admin
    /usr/local/bin/php artisan db:seed --class=InitialDataSeeder --force
'
```

### 3.6. Install the cPanel bootstrap shim

Wire `public_html/` to the Laravel app:

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet
    cp laravel-app/deploy/cpanel-bootstrap-index.php public_html/index.php
    cp laravel-app/deploy/cpanel-htaccess          public_html/.htaccess
    chmod 644 public_html/index.php public_html/.htaccess

    # Expose storage/app/public to the web (laravel-app is OUTSIDE public_html)
    ln -snf ../laravel-app/storage/app/public public_html/storage
'
```

Verify the layout:

```text
/home/archivet/
|-- laravel-app/
|   |-- app/  bootstrap/  config/  database/  resources/  routes/
|   |-- public/index.php                <-- real Laravel entrypoint
|   |-- storage/  vendor/
|   `-- .env                            <-- secrets, NEVER reachable from the web
`-- public_html/
    |-- index.php                       <-- bootstrap shim (sec. 3.6)
    |-- .htaccess                       <-- bootstrap shim (sec. 3.6)
    `-- storage -> ../laravel-app/storage/app/public
```

### 3.7. Optimize and verify

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet/laravel-app
    /usr/local/bin/php artisan optimize
    /usr/local/bin/php artisan filament:cache-components || true
    /usr/local/bin/php artisan icons:cache               || true
'
```

Smoke test from your workstation:

```bash
curl -sS -o /dev/null -w "HTTP %{http_code}\n" https://archivetool.eu/admin/login
# Expected: HTTP 200
```

If you get anything other than `200`, do NOT enable the GitHub Actions
workflow — fix the issue first (see section 6).

---

## 4. Continuous deploys (automated)

After the first-time bootstrap succeeds, every push to `main` triggers
`.github/workflows/deploy-archivetool.yml`, which:

1. checks out the repository at the pushed SHA,
2. opens an SSH agent with `SSH_PRIVATE_KEY_DEPLOY`,
3. runs `deploy/post-deploy.sh` over SSH on the server.

The remote script (`deploy/post-deploy.sh`) does:

- `artisan down` (enter maintenance mode);
- `git fetch && git reset --hard origin/main`;
- `composer install --no-dev --optimize-autoloader`;
- `artisan migrate --force`;
- `artisan optimize:clear && artisan optimize`;
- `artisan filament:cache-components` and `artisan icons:cache`;
- chmod hardening on `storage/`, `bootstrap/cache/`, and `.env`;
- `artisan up` (leave maintenance mode).

A workflow `workflow_dispatch` trigger is also wired so you can re-deploy a
specific branch from the GitHub UI in an emergency.

### 4.1. Manual trigger

Go to **Actions -> Deploy to archivetool.eu -> Run workflow**, optionally
specify a branch (default: `main`), and click **Run workflow**.

---

## 5. Rollback

Each release is a plain git checkout, so rollback is `git reset` to the
previous good SHA:

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet/laravel-app
    /usr/local/bin/php artisan down
    git fetch --all
    git reset --hard <PREVIOUS_GOOD_SHA>
    /usr/local/bin/composer install --no-dev --optimize-autoloader \
                                    --no-interaction --prefer-dist
    # NOTE: do NOT auto-rollback migrations. Review migration history first
    # and decide explicitly whether to roll back the schema.
    /usr/local/bin/php artisan optimize:clear
    /usr/local/bin/php artisan optimize
    /usr/local/bin/php artisan up
'
```

For a database rollback use the timestamped dump from section 3.2:

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet
    gunzip -c archivet_nra_backup_<DATE>.sql.gz \
      | mysql --defaults-file=~/.my.cnf archivet_nra
'
```

---

## 6. Troubleshooting

### 6.1. `HTTP 500` after deploy

Check `/home/archivet/laravel-app/storage/logs/laravel.log` and the latest
`deploy-<timestamp>.log` next to it. Most common causes:

- `.env` missing a key (e.g. `APP_KEY` empty -> 500 on every page);
- `storage/` not writable -> `chmod -R 775 storage bootstrap/cache`;
- a new package needs an env var that was never set in `.env`.

### 6.2. `HTTP 403` or directory listing

The `public_html/.htaccess` file is missing or malformed. Re-copy it from
`/home/archivet/laravel-app/deploy/cpanel-htaccess` (section 3.6).

### 6.3. CSS / JS not loading

Filament assets must be present at `laravel-app/public/css/filament/` and
`laravel-app/public/js/filament/`. If they're missing, the Filament
asset-build PR was not merged before deploy. Run on the server:

```bash
cd /home/archivet/laravel-app
/usr/local/bin/php artisan filament:assets
```

If that command also fails, the Filament asset bundle is stale; the fix is
to run `npm run build` locally, commit `public/build/` and `public/css/`
and `public/js/`, and push.

### 6.4. `SSH_HOST` rejected by `ssh-keyscan`

Some cPanel hosts rate-limit incoming connections. Re-run the workflow; if
it still fails, generate `~/.ssh/known_hosts` once manually and store it
as a base64-encoded secret instead of letting the workflow scan on every
run.

### 6.5. Bootstrap shim returns "Application offline"

`/home/archivet/laravel-app/public/index.php` is missing or unreadable.
Confirm `laravel-app/` exists and is owned by `archivet:archivet`, then
re-run `chmod 755 laravel-app laravel-app/public`.

### 6.6. Storage symlink "loops"

If `public_html/storage` is a relative symlink (`../laravel-app/...`) and
the resolution loops, replace it with an absolute one:

```bash
ssh archivetool '
    ln -snf /home/archivet/laravel-app/storage/app/public \
            /home/archivet/public_html/storage
'
```

---

## Appendix A — Files shipped with this PR

| File                                            | Purpose                                                                       |
|-------------------------------------------------|-------------------------------------------------------------------------------|
| `deploy/cpanel-bootstrap-index.php`             | Copied to `public_html/index.php`; pivots to `laravel-app/public/index.php`.  |
| `deploy/cpanel-htaccess`                        | Copied to `public_html/.htaccess`; HTTPS redirect + rewrite to the shim.      |
| `deploy/post-deploy.sh`                         | Idempotent incremental deploy script executed over SSH by GitHub Actions.    |
| `.github/workflows/deploy-archivetool.yml`      | CI workflow that runs `post-deploy.sh` on push to `main`.                     |
| `docs/deploy/archivetool.md`                    | This document.                                                                 |
| `tests/Feature/DeployArtifactsTest.php`         | Sanity tests on the deploy artifacts (syntax, permissions, references).      |
