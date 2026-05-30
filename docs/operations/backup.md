# Backup & restore runbook - Batch List Tool

Operational runbook for the daily automated backups required by
**RFQ-2026-06 §3.4.2**.

This document covers:

1. What gets backed up (and what does not)
2. Where backups land
3. Cron schedule and how to verify it
4. How to confirm a backup actually ran
5. How to download a backup off the production box
6. **How to restore on a fresh server** (step-by-step)
7. Off-site copy (current state + future plan)
8. Encryption key rotation
9. Monitoring (built-in + optional external)
10. Common failures and what to do about them

> All file paths in this document refer to the **production server**
> (SSH alias `archivetool` -> `cpanel19.vhosting-it.com`) unless
> explicitly prefixed with `(local)`.

---

## 1. What gets backed up

The backup is produced by `spatie/laravel-backup ^10`, driven by
`config/backup.php`. Two artefacts go into every archive:

1. **MySQL database dump** of the connection in `DB_CONNECTION`
   (production = `mysql`, database `archivet_nra`). Written by `mysqldump`,
   no compression at the dump layer (the surrounding zip handles
   compression).
2. **Filesystem mirror** of `base_path()` - the Laravel project root -
   with these exclusions:
   - `vendor/` (re-installed from `composer.lock` on restore)
   - `node_modules/` (not present on prod; built assets ship committed)
   - the backup process working directory itself

Everything else under `base_path()` is in the archive:
`.env`, `app/`, `config/`, `database/`, `public/`, `resources/`,
`routes/`, `storage/` (incl. uploaded files), `tests/`,
`composer.json`, `composer.lock`, `artisan`, `package*.json`, etc.

The whole archive is then **zipped with AES-256 encryption** using
`BACKUP_ARCHIVE_PASSWORD` from `.env` (`config/backup.php` -> `'password'`,
`'encryption' => 'default'` resolves to `ZipArchive::EM_AES_256`).

> If `BACKUP_ARCHIVE_PASSWORD` is empty, **no encryption** is applied.
> Always set it on production.

---

## 2. Where backups land

| Setting                | Value                                                  |
|------------------------|--------------------------------------------------------|
| Destination disk       | `local` (the `local` Laravel filesystem disk)          |
| Root on the disk       | `storage/app/` (per `config/filesystems.php` `local`)  |
| Archive subdirectory   | `storage/app/{APP_NAME}/`                              |
| Filename               | `YYYY-MM-DD-HH-mm-ss.zip`                              |
| Temporary working dir  | `storage/app/backup-temp/` (auto-cleaned)              |

On production with `APP_NAME=Laravel` (or whatever it is in `.env`):

```text
/home/archivet/public_html/storage/app/Laravel/2026-05-27-02-30-00.zip
```

Retention is governed by `config/backup.php` -> `cleanup.default_strategy`:

- keep every backup for 7 days
- keep one per day for 16 more days
- keep one per week for 8 weeks
- keep one per month for 4 months
- keep one per year for 2 years
- hard ceiling at 5000 MB - oldest archives are removed past that

---

## 3. Cron schedule

Three scheduled commands are defined in `routes/console.php`:

| Cron expression  | Command              | Purpose                              |
|------------------|----------------------|--------------------------------------|
| `30 2 * * *`     | `backup:run`         | Daily backup at 02:30 UTC            |
| `0 3 * * *`      | `backup:clean`       | Prune old archives at 03:00 UTC      |
| `0 8 * * 1`      | `backup:monitor`     | Health check every Monday at 08:00   |

All three are wrapped with:

- `->withoutOverlapping()` - a second run skips if the previous hasn't finished
- `->onOneServer()` - safe even if the app is ever load-balanced
- `->emailOutputOnFailure(env('MAIL_BACKUP_RECIPIENT'))` - failure mail goes to the address(es) in `MAIL_BACKUP_RECIPIENT`

`backup:clean` runs **after** `backup:run` (03:00 vs 02:30) so the
freshly written archive is in place before pruning evaluates the
retention window.

### 3.1. Verify the schedule is registered

On any environment (local or prod):

```bash
php artisan schedule:list
```

Expected (excerpt):

```text
0  3 * * *  php artisan backup:clean ........... Next Due: ...
30 2 * * *  php artisan backup:run ............. Next Due: ...
0  8 * * 1  php artisan backup:monitor ......... Next Due: ...
```

If any line is missing, somebody broke `routes/console.php`. The
`tests/Feature/Console/BackupScheduleTest.php` Pest test will fail in
CI in that case.

### 3.2. cPanel cron

The Laravel scheduler needs an OS-level cron entry that runs every
minute and delegates to `php artisan schedule:run`. On `archivetool`
this is added once via cPanel -> Cron Jobs:

```cron
* * * * * cd /home/archivet/public_html && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Confirm it is active:

```bash
ssh archivetool 'crontab -l | grep schedule:run'
```

---

## 4. Confirm a backup actually ran

Three independent signals - check at least two:

1. **The archive exists on disk**

   ```bash
   ssh archivetool 'ls -lh /home/archivet/public_html/storage/app/Laravel/ | tail -10'
   ```

   You should see today's zip with a non-trivial size (>1 MB once the
   uploads folder has data).

2. **`backup:list` agrees**

   ```bash
   ssh archivetool 'cd /home/archivet/public_html && php artisan backup:list'
   ```

   Output shows the disk, the count, the latest archive timestamp, and
   the total size used.

3. **Mail arrived at `MAIL_BACKUP_RECIPIENT`**

   `BackupWasSuccessfulNotification` is wired in `config/backup.php`
   under `notifications.notifications` and fires after every
   successful `backup:run`. If you stop receiving these, something is
   wrong even if checks 1 and 2 still look fine (the mail pipeline
   itself is broken, which means a failure mail won't reach you
   either).

4. **Laravel log**

   ```bash
   ssh archivetool 'tail -n 100 /home/archivet/public_html/storage/logs/laravel.log | grep -i backup'
   ```

---

## 5. Download a backup off production

cPanel shared hosting allows `scp` over the standard SSH port:

```bash
# (local) - pull the most recent archive
ssh archivetool 'ls -t /home/archivet/public_html/storage/app/Laravel/*.zip | head -1' \
  | xargs -I{} scp archivetool:{} ./

# or a specific date
scp archivetool:/home/archivet/public_html/storage/app/Laravel/2026-05-27-02-30-00.zip ./
```

Verify the archive is intact and encrypted:

```bash
unzip -l 2026-05-27-02-30-00.zip | head
# "encrypted" should appear in the entries column - this is AES-256
```

---

## 6. Restore the database (in-place, from the UI)

> **Restoring overwrites the live database.** This is the single most
> destructive operation in the application. The guided flow takes a fresh
> DB-only safety snapshot *before* it touches anything, and is restricted to
> **super_admin only**.

### 6.0. Guided restore (Backup Center UI) — super_admin only

The **Restore** row action on the backups list is available **only to
super_admin** users. It is hidden for admin, editor and viewer, and the server
re-checks the role on submit (defence in depth — not just UI hiding;
`App\Filament\Pages\Settings\BackupHealthPage::restoreFromBackup()` calls
`abort_unless(... hasRole('super_admin'), 403)`).

1. Open **Administration → Backup & Health**.
2. In the backups list, click **Restore** on the desired `.zip` archive.
3. In the confirmation modal you must:
   - tick **"I understand this will OVERWRITE the live database"**; and
   - **type the exact current database name** shown in the helper text.
     A mismatch aborts the restore server-side with a clear validation error
     and nothing is imported.
4. On submit the system runs `App\Actions\Backup\RestoreDatabase::restore()`,
   which, in order:
   1. **STEP A** — takes a pre-restore **safety snapshot** (`backup:run
      --only-db`, recorded as a `backup_runs` row `type=db` "pre-restore safety
      snapshot"). If this fails the restore is **aborted** and the database is
      left untouched.
   2. **STEP B** — extracts the `.sql` dump from `db-dumps/` inside the chosen
      archive (ZipArchive) to a temp file.
   3. **STEP C** — imports the dump into the default connection. It prefers the
      `mysql` client (run via Symfony Process with an argument array — no shell,
      dump streamed over stdin, password passed via `MYSQL_PWD`), otherwise
      falls back to `DB::unprepared()`.
   4. **STEP D** — records a `backup_runs` row `type=restore` with the outcome
      (success/failed) and who triggered it.

A safety snapshot is therefore **always** taken first; if the snapshot step
fails the import is never reached.

### 6.1bis. Manual restore fallback (database only)

If the UI is unavailable, restore manually from the shell:

1. **Take a fresh backup first:** `php artisan backup:run --only-db`.
2. Locate the desired `.zip` on the backup disk
   (default: `storage/app/<app-name>/`).
3. Unzip it and extract the SQL dump from `db-dumps/`.
4. Import it: `mysql -u <user> -p <database> < db-dumps/<dump>.sql`.

---

## 6. Restore on a fresh server

This is the disaster-recovery path. Target: a brand-new Linux box with
PHP 8.3+, MySQL 8 / MariaDB 10.6+, Composer, and an empty DB schema.

> **Time budget:** ~30 minutes on a clean VPS with a recent backup
> (DB <100 MB, storage <2 GB). Multiply by archive size for prod-scale data.

### 6.1. Prerequisites on the new box

```bash
# (new server)
php -v                     # >= 8.3
mysql --version            # 8.x or MariaDB 10.6+
composer --version         # 2.x
unzip -v                   # AES-256 support (Info-ZIP unzip 6.0+, or 7zip)
```

If `unzip` on the box doesn't speak AES-256 (some old Info-ZIP builds),
install `p7zip` and use `7z x` instead.

### 6.2. Provision Laravel skeleton + empty DB

```bash
# (new server) - choose the deploy dir; mirror prod layout if possible
sudo mkdir -p /var/www/batch-list-tool
sudo chown $USER:$USER /var/www/batch-list-tool
cd /var/www/batch-list-tool

# create the target MySQL database + user
mysql -u root -p <<'SQL'
CREATE DATABASE archivet_nra CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'archivet_nrauser'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON archivet_nra.* TO 'archivet_nrauser'@'localhost';
FLUSH PRIVILEGES;
SQL
```

### 6.3. Extract the archive

```bash
# (new server) - copy the archive in first (scp / rsync from your laptop)
cd /var/www/batch-list-tool
unzip -P "$BACKUP_ARCHIVE_PASSWORD" /path/to/2026-05-27-02-30-00.zip -d ./_restored

# the zip preserves the original relative tree under base_path()
# move the project files to the deploy dir
shopt -s dotglob
mv ./_restored/* ./
rmdir ./_restored
```

> The archive root contains the database dump under `db-dumps/mysql-mysql.sql`
> (or similarly named per `config/backup.php` `database_dump_filename_base`).
> Note that path before continuing.

### 6.4. Restore the database

```bash
# (new server)
mysql -u archivet_nrauser -p archivet_nra < db-dumps/mysql-mysql.sql
# (or whatever the dump file is named after extraction)
```

Verify a known table came back:

```bash
mysql -u archivet_nrauser -p -e "SELECT COUNT(*) FROM archivet_nra.documents;"
```

### 6.5. Re-build the application

```bash
# (new server)
composer install --no-dev --prefer-dist --optimize-autoloader

# .env came with the archive; re-check it points at the new DB
$EDITOR .env
# verify DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD
# verify APP_URL, BACKUP_ARCHIVE_PASSWORD, MAIL_BACKUP_RECIPIENT
# regenerate APP_KEY only if you intend a brand-new install:
#   php artisan key:generate
# DO NOT regenerate APP_KEY if you want existing encrypted columns/
# sessions to keep working - the restored .env already has the
# matching key.

php artisan migrate --force       # apply any migrations newer than the dump
php artisan storage:link
php artisan optimize:clear
php artisan optimize
```

Set permissions so the web server can write where it must:

```bash
# (new server) - adjust user/group to the web server account
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

### 6.6. Smoke test

```bash
# (new server) - point a vhost at /var/www/batch-list-tool/public
# then from your laptop:
curl -s -o /dev/null -w "%{http_code}\n" https://new-host.example.com/admin/login
# expected: 200
```

Log in with a known admin account. Spot-check that:

- Documents list paginates and renders.
- A document detail page opens (storage paths resolved).
- Audit log shows recent entries from before the restore.

### 6.7. Re-arm the scheduler on the new box

```bash
# (new server)
crontab -e
# add:
* * * * * cd /var/www/batch-list-tool && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Verify with `php artisan schedule:list` that the three backup entries
appear. **A restored site without its scheduler is one disaster away
from a permanent data loss event - do not skip this step.**

---

## 7. Off-site copy

> **Current state: pre-wired, activates on env var.** `config/backup.php`
> auto-enables the `s3` disk when `BACKUP_S3_BUCKET` is set; the disk
> definition itself reads `AWS_*` env vars from `config/filesystems.php`,
> so any S3-compatible service (Wasabi, Backblaze B2, OVH, AWS) drops in
> with credentials only — zero code change.

Suggested options for the NRA Malta engagement:

- **Wasabi** (cheapest egress-free S3, EU region available)
- **Backblaze B2** (S3-compatible)
- **AWS S3 Glacier Deep Archive** if storage volume justifies it

### Activation procedure

Add these to `.env` on prod (replace placeholders):

```env
# Bucket NAME (also flips disks list in config/backup.php to ['local', 's3'])
BACKUP_S3_BUCKET=nra-malta-backups

# Standard Laravel S3 driver env (config/filesystems.php reads these)
AWS_ACCESS_KEY_ID=AKIAxxxxx
AWS_SECRET_ACCESS_KEY=xxxxxxxxxxxxxx
AWS_DEFAULT_REGION=eu-central-1
AWS_BUCKET=nra-malta-backups
# For non-AWS S3-compatible (Wasabi, B2, OVH) set both:
# AWS_ENDPOINT=https://s3.eu-central-1.wasabisys.com
# AWS_USE_PATH_STYLE_ENDPOINT=true
```

Then:

```bash
# (prod) - clear config cache so the new env is picked up
ssh archivetool "cd /home/archivet/public_html && /opt/cpanel/ea-php84/root/usr/bin/php artisan config:clear"

# Run a manual backup to verify the off-site copy lands:
ssh archivetool "cd /home/archivet/public_html && /opt/cpanel/ea-php84/root/usr/bin/php artisan backup:run"
# Look for "Copying zip to disk named [s3]..." in the output.
```

Every subsequent `backup:run` writes the archive to both `local` AND `s3`
atomically. `backup:monitor` will check both. `backup:clean` retention
rules apply per-disk via the existing `cleanup.default_strategy` config.

If `BACKUP_S3_BUCKET` is unset (current local + staging state) the disks
list silently stays at `['local']` — single-failure risk but no broken
deploys from missing credentials.

---

## 8. Rotate `BACKUP_ARCHIVE_PASSWORD`

The encryption password should be rotated periodically (suggested:
quarterly, and **always** after any suspected leak of `.env`).

```bash
# (local) - generate a new strong password
NEW_PASS=$(openssl rand -base64 48 | tr -d '/+=' | head -c 48)
echo "$NEW_PASS"
# store it in your password manager NOW

# (prod) - update .env
ssh archivetool
cd /home/archivet/public_html
cp .env .env.bak-$(date +%F)
sed -i "s|^BACKUP_ARCHIVE_PASSWORD=.*|BACKUP_ARCHIVE_PASSWORD=${NEW_PASS}|" .env
php artisan config:clear

# force a fresh backup so we have one under the new password
php artisan backup:run
php artisan backup:list   # confirm the new archive

# verify you can open the new archive with the new password (do this
# from your laptop, not on prod)
exit
scp archivetool:/home/archivet/public_html/storage/app/Laravel/<latest>.zip ./
unzip -P "$NEW_PASS" -t <latest>.zip   # -t = test only, no extraction
```

**Do not delete the old password from your password manager** until
all archives encrypted with it have aged out of the retention window
(default: ~2 years). Otherwise older archives become unrecoverable.

---

## 9. Monitoring

### 9.1. Built-in (already configured)

- `backup:monitor` runs every Monday 08:00 UTC and reads
  `config/backup.php` -> `monitor_backups`. The default policy is:
  - `MaximumAgeInDays => 1` - alert if the freshest backup is older than 24 h
  - `MaximumStorageInMegabytes => 5000` - alert if disk usage exceeds 5 GB
- Failures fire `UnhealthyBackupWasFoundNotification` (`config/backup.php`)
  to mail.
- Successful runs fire `HealthyBackupWasFoundNotification` to mail,
  giving you a weekly "still alive" heartbeat.

### 9.2. Optional - Healthchecks.io

If you want a dead-man's-switch independent of your own mail
infrastructure, sign up at healthchecks.io and add to the schedule:

```php
// routes/console.php
Schedule::command('backup:run')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(env('MAIL_BACKUP_RECIPIENT'))
    ->thenPing(env('HEALTHCHECK_BACKUP_URL'));
```

Healthchecks.io then alerts you if it does NOT hear from the cron
within the grace window.

### 9.3. Optional - Sentry

If `sentry/sentry-laravel` is installed, exceptions thrown by
`backup:run` already report to Sentry through the global handler.
No additional wiring needed.

---

## 10. Common failures

### 10.1. "No space left on device" / disk full

Symptom: backup mail says "Backup failed", log shows `fwrite():
write failed` or similar.

Action:
```bash
ssh archivetool 'df -h /home/archivet'
ssh archivetool 'du -sh /home/archivet/public_html/storage/app/Laravel/'
# if the backup dir is huge, prune manually:
ssh archivetool 'cd /home/archivet/public_html && php artisan backup:clean'
# if /home is full from something else (logs, uploads), clean those first.
```

If pruning isn't enough, lower
`cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than`
in `config/backup.php` from 5000 to a smaller cap.

### 10.2. "Cannot open zip archive" on restore

Cause: wrong `BACKUP_ARCHIVE_PASSWORD`, or your local `unzip` can't
do AES-256.

Action:
```bash
# confirm AES-256 support
unzip -v | head -3
# fallback - 7zip handles AES-256 reliably
7z x -p"$BACKUP_ARCHIVE_PASSWORD" 2026-05-27-02-30-00.zip
```

If 7zip also fails, the password is wrong. Try the previous one from
your password manager (recent rotation?).

### 10.3. `mysqldump: command not found`

The spatie/db-dumper invokes `mysqldump` from `$PATH`. On bare cPanel
shared hosts the binary lives at `/usr/bin/mysqldump`. Verify:

```bash
ssh archivetool 'which mysqldump && mysqldump --version'
```

If missing, set the explicit path in `config/database.php` for the
`mysql` connection:

```php
'dump' => [
    'dump_binary_path' => '/usr/bin/',
],
```

### 10.4. DB credentials wrong after restore

Symptom: `php artisan migrate --force` returns "Access denied for
user". The restored `.env` carries production credentials that don't
match the new DB you created in step 6.2.

Action: edit `.env`, set `DB_HOST/DB_USERNAME/DB_PASSWORD` to match
the new server, then `php artisan config:clear` and retry.

### 10.5. No failure mail received

The schedule uses `emailOutputOnFailure(env('MAIL_BACKUP_RECIPIENT'))`.
If `MAIL_BACKUP_RECIPIENT` is empty or `MAIL_MAILER` is `log`, no
mail leaves the box.

Action:
```bash
ssh archivetool 'grep -E "^MAIL_|^BACKUP_" /home/archivet/public_html/.env'
# fix as needed, then:
ssh archivetool 'cd /home/archivet/public_html && php artisan config:clear'
# trigger a test mail
ssh archivetool 'cd /home/archivet/public_html && php artisan tinker --execute="Mail::raw(\"backup mail test\", fn(\$m) => \$m->to(env(\"MAIL_BACKUP_RECIPIENT\"))->subject(\"backup test\"));"'
```

### 10.6. `Schedule::command()` not firing

Usually means cPanel cron isn't installed, or it points at the wrong
path:

```bash
ssh archivetool 'crontab -l'
ssh archivetool 'cd /home/archivet/public_html && php artisan schedule:list'
# manually invoke to confirm the schedule fires anything
ssh archivetool 'cd /home/archivet/public_html && php artisan schedule:run -v'
```

---

## 11. DB safety (pre-migrate snapshot + safe refresh)

Two safeguards make destructive database operations recoverable.

### 11.1. Automatic pre-migrate snapshot

`App\Providers\AppServiceProvider` listens for Laravel's
`Illuminate\Database\Events\MigrationsStarted` event. The moment a migration
run begins on a **real (non-sqlite)** connection, it takes a DB-only safety
backup before the migrations touch the schema:

```php
Artisan::call('backup:run', ['--only-db' => true]);
```

This covers every destructive path (`migrate:fresh`, `migrate:refresh`,
`migrate:rollback`) as well as a plain `migrate` — so a bad migration on a DB
holding real data is always preceded by a recoverable snapshot.

Decision logic (`AppServiceProvider::shouldRunPreMigrateBackup()`, unit-tested):
the snapshot is **skipped** when

- the default connection driver is `sqlite` (local throwaway DB; the test
  suite also runs on SQLite `:memory:` and must never trigger spatie),
- the automated test suite is running (`app()->runningUnitTests()`), or
- the escape hatch `BACKUP_SKIP_PRE_MIGRATE=true` is set.

It runs once per process (guarded by a static flag) and is wrapped in
try/catch: on a brand-new install where spatie/laravel-backup isn't usable
yet, the failure is logged as a **warning** and migrations continue — a
first-time `migrate` is never blocked by a missing backup.

> **Emergency override:** set `BACKUP_SKIP_PRE_MIGRATE=true` in `.env` (then
> `php artisan config:clear`) to disable the pre-migrate snapshot, e.g. when
> the disk is full and you must migrate immediately.

### 11.2. `db:refresh-safe` — wipe but always repopulate

`migrate:fresh` leaves the dev DB empty (no admin to log in with). Use
`db:refresh-safe` instead — it drops everything, then **always** repopulates:

```bash
php artisan db:refresh-safe            # fresh + admin seed + sample import + lookup backfill
php artisan db:refresh-safe --samples=false   # fresh + admin seed only (no sample data)
php artisan db:refresh-safe --force    # skip the confirmation prompt
```

What it does, in order:

1. `migrate:fresh` (all tables dropped & recreated)
2. `db:seed --class=InitialDataSeeder` (admin login + roles — **always**)
3. `nra:import-samples` (default; disable with `--samples=false`)
4. Backfills the `document_types` / `practices` lookup tables from the
   DISTINCT values in the freshly imported `documents` (the create-migration
   only seeds those when documents already exist, so after a fresh+import they
   would otherwise be empty).

Safety rails: it **aborts on `production`**, and on any non-`local`
environment it asks for confirmation unless `--force` is passed.

> **Tests never touch real data.** The whole test suite runs against SQLite
> `:memory:`; the pre-migrate snapshot is skipped on sqlite, and
> `db:refresh-safe` is exercised in tests only via its production-abort and
> signature checks — it is never actually run against a real database in CI.

---

## Reference

- `config/backup.php` - full configuration (do not modify without re-testing)
- `app/Providers/AppServiceProvider.php` - pre-migrate snapshot listener (`shouldRunPreMigrateBackup`)
- `app/Console/Commands/RefreshSafe.php` - the `db:refresh-safe` command
- `tests/Feature/Backup/DbSafeguardTest.php` - guards both safeguards
- `routes/console.php` - the three `Schedule::command(...)` entries
- `tests/Feature/Console/BackupScheduleTest.php` - CI guard that pins the cron expressions
- `vendor/spatie/laravel-backup/` - upstream package (v10.2.x)
- RFQ-2026-06 §3.4.2 - "Daily automated backups with encryption and tested restore" (the requirement this runbook closes)
