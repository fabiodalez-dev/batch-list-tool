# Deploy procedure - archivetool.eu

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

## Deploy secret configuration (resolved 2026-05-28)

> ✅ `DEPLOY_PATH` is set to `/home/archivet/public_html` and the
> auto-deploy workflow has been running green since the first production
> cut-over (30+ successful `Deploy to archivetool.eu` runs verified).
>
> The five GitHub secrets the deploy workflow needs:
> `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY_DEPLOY`, `SSH_PASSPHRASE` (empty
> for the no-passphrase deploy key), `DEPLOY_PATH=/home/archivet/public_html`.
>
> Transient failure note: the `Trust deploy host` step runs `ssh-keyscan`;
> if the VHosting host is briefly unreachable it exits non-zero under
> `set -e`. Re-run the workflow (`gh workflow run "Deploy to archivetool.eu"
> --ref main`) — it is idempotent.

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
| Laravel app dir  | `/home/archivet/public_html/` (same as web root - see below)   |
| Front controller | `/home/archivet/public_html/public/index.php`                  |
| PHP CLI          | `/usr/local/bin/php` (8.3.31)                                  |
| Composer         | `/usr/local/bin/composer` (2.9.8)                              |
| Git              | `/usr/local/cpanel/3rdparty/lib/path-bin/git` (2.48.2)         |
| Node / npm       | NOT installed - Filament assets must be pre-built and committed |
| Database         | MySQL `archivet_nra` (4.7 MB), user `archivet_nrauser`         |
| TLS              | terminated at the cPanel reverse proxy (X-Forwarded-Proto)     |

### 1.1. Layout: Laravel directly in `public_html/`

Since the 2026-05-27 pivot, the Laravel project lives **directly inside**
the cPanel document root:

```text
/home/archivet/
`-- public_html/                         <-- cPanel document root AND Laravel root
    |-- .htaccess                        <-- copy of deploy/cpanel-htaccess (SECURITY)
    |-- .env                             <-- secrets (denied by .htaccess)
    |-- app/                             <-- denied by RedirectMatch 404
    |-- bootstrap/                       <-- denied
    |-- config/                          <-- denied
    |-- database/                        <-- denied
    |-- deploy/                          <-- denied
    |-- docs/                            <-- denied
    |-- public/                          <-- ONLY directory served by Apache
    |   |-- index.php                    <-- Laravel front controller
    |   |-- .htaccess                    <-- Laravel's own htaccess
    |   |-- css/  js/  build/            <-- compiled assets
    |   `-- storage -> ../storage/app/public    (symlink from `artisan storage:link`)
    |-- resources/                       <-- denied
    |-- routes/                          <-- denied
    |-- storage/                         <-- denied
    |-- tests/                           <-- denied
    |-- vendor/                          <-- denied
    |-- artisan                          <-- denied (FilesMatch)
    |-- composer.json composer.lock      <-- denied
    `-- ...
```

**Why this layout instead of "Laravel outside public_html"?**

- **Pro**: zero bootstrap complexity. `git pull` lands exactly where the
  deploy script expects. No shim PHP file, no symlinks across home
  subtrees, no `chdir()` games.
- **Con**: `app/`, `vendor/`, `.env`, etc. are physically reachable by
  HTTP. **A misconfigured `.htaccess` would expose `.env` at
  `https://archivetool.eu/.env`.**

The trade-off is acceptable **only** because `deploy/cpanel-htaccess` is
the explicit, audited security layer that closes that hole. See section 1.2.

### 1.2. The `.htaccess` is the critical security layer

`deploy/cpanel-htaccess` (installed as `/home/archivet/public_html/.htaccess`)
does two jobs. Both are mandatory.

**(a) Routing** - rewrites every request to `public/index.php`:

- If the URI maps to an actual file or symlink inside `public/` (CSS, JS,
  images, `storage/`), serve it.
- Otherwise forward to `public/index.php` (Laravel's front controller).

**(b) Hard-deny of sensitive paths** - the part that makes this layout
safe:

- `<FilesMatch "^\.">` blocks every dotfile - `.env`, `.env.production`,
  `.git`, `.github`, `.editorconfig`, etc.
- `<FilesMatch>` on `composer.{json,lock}`, `package*.json`, `artisan`,
  `phpunit.xml`, `*.sh`, `*.md`, `*.yml`, `*.yaml` blocks the rest of the
  project-root metadata files.
- `RedirectMatch 404 ^/(\.git|\.github|app|bootstrap|config|database|`
  `deploy|docs|node_modules|resources|routes|storage|tests|vendor)(/|$)`
  returns 404 (not 403) for the sensitive directories. 404 is preferable
  because it doesn't reveal that the path exists.

After every deploy, `deploy/post-deploy.sh` re-copies
`deploy/cpanel-htaccess` to `public_html/.htaccess` so any drift between
the repo and the live file is auto-corrected.

**Smoke test the security layer after every deploy:**

```bash
for path in /.env /composer.json /artisan /app/ /vendor/ /storage/logs/laravel.log /.git/config; do
    code=$(curl -sS -o /dev/null -w "%{http_code}" "https://archivetool.eu${path}")
    echo "${code}  https://archivetool.eu${path}"
done
# Expected: 403 or 404 on EVERY line. Anything that returns 200 is a leak.
```

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
| `DEPLOY_PATH`            | `/home/archivet/public_html`                         | absolute path of the Laravel project on the server (now == web root) |
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

### 3.1. Backup the current `public_html/`

`public_html/` currently contains either (a) the OLD raw-PHP MVC app or
(b) the previous Laravel deploy with the bootstrap shim. Move it aside
before touching anything:

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet
    if [[ -d public_html && ! -L public_html ]]; then
        mv public_html "public_html.legacy_$(date -u +%Y-%m-%d)"
    fi
    # Also archive the laravel-app/ directory from the previous PR if present
    if [[ -d laravel-app && ! -L laravel-app ]]; then
        mv laravel-app "laravel-app.legacy_$(date -u +%Y-%m-%d)"
    fi
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

A schema-only safety backup is also useful in case the migrate step
clobbers something unexpected:

```bash
ssh archivetool '
    cd /home/archivet
    mysqldump --defaults-file=~/.my.cnf --no-data archivet_nra \
              > "archivet_nra_schema_$(date -u +%Y-%m-%d).sql"
'
```

### 3.3. Clone the repository DIRECTLY into `public_html/`

The pivot moves Laravel into the cPanel document root, so the clone target
is `public_html/` itself - NOT `laravel-app/` anymore.

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet
    git clone --branch main \
              https://github.com/fabiodalez-dev/batch-list-tool.git \
              public_html
    cd public_html
    /usr/local/bin/composer install --no-dev --optimize-autoloader \
                                    --no-interaction --prefer-dist
'
```

### 3.4. Populate `.env`

Generate `/home/archivet/public_html/.env` from `.env.example` and edit:

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet/public_html
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
    cd /home/archivet/public_html
    /usr/local/bin/php artisan key:generate --force
'
```

### 3.5. Migrate the schema and seed initial data

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet/public_html
    /usr/local/bin/php artisan migrate --force
    /usr/local/bin/php artisan shield:generate --all --panel=admin
    /usr/local/bin/php artisan db:seed --class=InitialDataSeeder --force
'
```

### 3.6. Install the `.htaccess` + storage symlink

This step installs the security-critical `.htaccess` and wires Laravel's
`public/storage` symlink. **Do not skip the `.htaccess` step** - without
it, every file under `public_html/` (including `.env`) becomes reachable
over HTTP.

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet/public_html
    cp deploy/cpanel-htaccess .htaccess
    chmod 644 .htaccess
    /usr/local/bin/php artisan storage:link
'
```

The complete first-time bootstrap, as a single block, is:

```bash
ssh archivet@cpanel19.vhosting-it.com '
    set -euo pipefail
    cd ~

    # Backup
    [[ -d public_html && ! -L public_html ]] && \
        mv public_html "public_html.legacy_$(date -u +%Y-%m-%d)"
    mysqldump --defaults-file=~/.my.cnf --no-data archivet_nra \
              > "archivet_nra_schema_$(date -u +%Y-%m-%d).sql"

    # Clone directly into public_html
    git clone --branch main \
              https://github.com/fabiodalez-dev/batch-list-tool.git \
              public_html
    cd public_html

    /usr/local/bin/composer install --no-dev --optimize-autoloader \
                                    --no-interaction --prefer-dist

    cp .env.example .env
    # MANUAL STEP: edit .env to set DB_PASSWORD, APP_URL, SMTP, ...
    /usr/local/bin/php artisan key:generate --force
    /usr/local/bin/php artisan migrate --force
    /usr/local/bin/php artisan shield:generate --all --panel=admin
    /usr/local/bin/php artisan db:seed --class=InitialDataSeeder --force
    /usr/local/bin/php artisan storage:link

    cp deploy/cpanel-htaccess .htaccess

    # Hardening
    chmod -R 775 storage bootstrap/cache
    chmod 600 .env
    chmod 644 .htaccess
'
```

### 3.7. Optimize and verify

```bash
ssh archivetool '
    set -euo pipefail
    cd /home/archivet/public_html
    /usr/local/bin/php artisan optimize
    /usr/local/bin/php artisan filament:cache-components || true
    /usr/local/bin/php artisan icons:cache               || true
'
```

Smoke test from your workstation:

```bash
# (1) Filament admin login page must respond 200
curl -sS -o /dev/null -w "HTTP %{http_code}\n" https://archivetool.eu/admin/login
# Expected: HTTP 200

# (2) Sensitive paths must NOT be reachable
for path in /.env /composer.json /artisan /app/ /vendor/ /.git/config; do
    code=$(curl -sS -o /dev/null -w "%{http_code}" "https://archivetool.eu${path}")
    echo "${code}  https://archivetool.eu${path}"
done
# Expected: 403 or 404 on EVERY line. Any 200 is a security regression.
```

If the admin login test returns anything other than `200`, OR if any of
the sensitive paths returns `200`, do NOT enable the GitHub Actions
workflow - fix the issue first (see section 6).

---

## 4. Continuous deploys (automated)

After the first-time bootstrap succeeds, every push to `main` triggers
`.github/workflows/deploy-archivetool.yml`, which:

1. checks out the repository at the pushed SHA,
2. opens an SSH agent with `SSH_PRIVATE_KEY_DEPLOY`,
3. runs `deploy/post-deploy.sh` over SSH on the server with
   `DEPLOY_PATH=/home/archivet/public_html`.

The remote script (`deploy/post-deploy.sh`) does:

- `artisan down --refresh=15` (enter maintenance mode);
- `git fetch && git reset --hard origin/main`;
- `composer install --no-dev --optimize-autoloader`;
- `artisan migrate --force`;
- `artisan optimize:clear && artisan optimize`;
- `artisan filament:cache-components` and `artisan icons:cache`;
- `artisan storage:link` (idempotent);
- chmod hardening on `storage/`, `bootstrap/cache/`, and `.env`;
- re-copies `deploy/cpanel-htaccess` to `.htaccess`;
- `artisan up` (leave maintenance mode).

The workflow also runs a post-deploy smoke test against
`https://archivetool.eu/admin/login` and fails if it doesn't return 200.

A workflow `workflow_dispatch` trigger is also wired so you can re-deploy
a specific branch from the GitHub UI in an emergency.

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
    cd /home/archivet/public_html
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

Check `/home/archivet/public_html/storage/logs/laravel.log` and the latest
`deploy-<timestamp>.log` next to it. Most common causes:

- `.env` missing a key (e.g. `APP_KEY` empty -> 500 on every page);
- `storage/` not writable -> `chmod -R 775 storage bootstrap/cache`;
- a new package needs an env var that was never set in `.env`.

### 6.2. `HTTP 403` or directory listing

The `public_html/.htaccess` file is missing or malformed. Re-copy it from
`/home/archivet/public_html/deploy/cpanel-htaccess` (section 3.6).

### 6.3. `HTTP 200` on `/.env` or `/composer.json` (CRITICAL)

A `200` response on any sensitive path means the security `.htaccess` is
missing or has been overwritten. Treat this as a security incident:

1. Immediately re-copy `deploy/cpanel-htaccess` to `public_html/.htaccess`.
2. Verify Apache picked it up: re-run the smoke test from section 3.7.
3. Rotate `APP_KEY` and any DB / SMTP credentials that may have leaked:
   `php artisan key:generate --force` and reset the MySQL password via
   cPanel.
4. Audit `storage/logs/access_log*` for any IP that hit the exposed path.

### 6.4. CSS / JS not loading

Filament assets must be present at `public_html/public/css/filament/` and
`public_html/public/js/filament/`. If they're missing, the Filament
asset-build PR was not merged before deploy. Run on the server:

```bash
cd /home/archivet/public_html
/usr/local/bin/php artisan filament:assets
```

If that command also fails, the Filament asset bundle is stale; the fix
is to run `npm run build` locally, commit `public/build/`, `public/css/`,
and `public/js/`, and push.

### 6.5. `SSH_HOST` rejected by `ssh-keyscan`

Some cPanel hosts rate-limit incoming connections. Re-run the workflow;
if it still fails, generate `~/.ssh/known_hosts` once manually and store
it as a base64-encoded secret instead of letting the workflow scan on
every run.

### 6.6. Storage symlink missing or broken

If `public_html/public/storage` is not a valid symlink, re-create it:

```bash
ssh archivetool '
    cd /home/archivet/public_html
    /usr/local/bin/php artisan storage:link
'
```

If `artisan storage:link` complains that the link already exists but
points to the wrong place, remove it and re-run:

```bash
ssh archivetool '
    rm -f /home/archivet/public_html/public/storage
    cd /home/archivet/public_html && /usr/local/bin/php artisan storage:link
'
```

---

## Appendix A - Files shipped with this PR

| File                                            | Purpose                                                                       |
|-------------------------------------------------|-------------------------------------------------------------------------------|
| `deploy/cpanel-htaccess`                        | Copied to `public_html/.htaccess`; (a) routes to `public/index.php`, (b) hard-denies sensitive files / dirs. |
| `deploy/post-deploy.sh`                         | Idempotent incremental deploy script executed over SSH by GitHub Actions.    |
| `.github/workflows/deploy-archivetool.yml`      | CI workflow that runs `post-deploy.sh` on push to `main`.                     |
| `docs/deploy/archivetool.md`                    | This document.                                                                 |
| `tests/Feature/DeployArtifactsTest.php`         | Sanity tests on the deploy artifacts (syntax, permissions, security rules).  |
