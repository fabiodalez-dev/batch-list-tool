# Queue Worker — Production Setup

## Why it exists

`QUEUE_CONNECTION=database` (see `.env.example`). Filament's bulk-import actions (Authority, Series, Batch, Box, Document, Accession) dispatch queued jobs. Without an active worker the jobs sit in the `jobs` table indefinitely and the import progress bar never moves.

## Cron entry (already live on archivetool.eu)

```
* * * * * cd /home/archivet/public_html && flock -n /tmp/archivet_queue.lock /opt/cpanel/ea-php84/root/usr/bin/php artisan queue:work --stop-when-empty --max-time=50 --tries=1 >> storage/logs/queue.log 2>&1
```

Key flags:
- `--stop-when-empty` — exits as soon as the `jobs` table is empty; cron re-spawns every minute.
- `--max-time=50` — hard exit after 50 s so the next cron tick never overlaps (cron fires every 60 s).
- `--tries=1` — failed jobs go straight to `failed_jobs`; no retry loop blocking the queue.
- `flock -n` — prevents two simultaneous workers if cron fires while a previous run is still within its 50-second window.

## Verify it is running

```bash
ssh archivetool "crontab -l | grep queue"
```

Check the log for recent activity:

```bash
ssh archivetool "tail -30 /home/archivet/public_html/storage/logs/queue.log"
```

Check whether jobs are pending:

```bash
ssh archivetool "cd /home/archivet/public_html && /opt/cpanel/ea-php84/root/usr/bin/php artisan queue:monitor database"
```

Or query directly:

```bash
ssh archivetool "cd /home/archivet/public_html && /opt/cpanel/ea-php84/root/usr/bin/php artisan tinker --execute=\"echo DB::table('jobs')->count() . ' pending, ' . DB::table('failed_jobs')->count() . ' failed';\""
```

## If imports stall

1. Check the log: `tail -50 storage/logs/queue.log` — look for PHP fatal errors or uncaught exceptions.
2. Check `failed_jobs` table: `php artisan queue:failed` lists all failures with their error messages.
3. Retry a specific failed job: `php artisan queue:retry <id>`.
4. Retry all failed jobs: `php artisan queue:retry all`.
5. Flush stale failed jobs: `php artisan queue:flush`.
6. If the `jobs` table has old stuck rows (e.g. from a crashed worker): `php artisan queue:clear database`.
7. Verify the cron is actually firing: `crontab -l` on the server.

## Local development

Run the worker manually (blocks the terminal):

```bash
php artisan queue:work --tries=1
```

Or use the shorter one-shot drain:

```bash
php artisan queue:work --stop-when-empty --tries=1
```
