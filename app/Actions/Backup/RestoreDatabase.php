<?php

declare(strict_types=1);

namespace App\Actions\Backup;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Guarded database restore from a spatie/laravel-backup archive.
 *
 * THE MOST DANGEROUS OPERATION IN THE APPLICATION — it overwrites the live
 * database. The flow is deliberately ordered:
 *
 *   STEP A — take a pre-restore SAFETY SNAPSHOT (backup:run --only-db).
 *            If it fails, ABORT before touching the database.
 *   STEP B — extract the .sql dump from db-dumps/ inside the zip.
 *   STEP C — import the dump into the default connection (mysql CLI, or
 *            DB::unprepared fallback).
 *   STEP D — record a BackupRun row describing the outcome.
 *
 * The safety snapshot is intentionally isolated in {@see safetySnapshot()} so
 * tests can subclass and override it to prove that the import (STEP C) is only
 * reached AFTER the snapshot returns successfully.
 */
class RestoreDatabase
{
    /**
     * Restore the database from the SQL dump contained in the given backup zip.
     *
     * @param string $disk The backup destination disk key the zip lives on
     *                     (recorded for provenance; the zip is read by path).
     * @param string $zipPath Absolute filesystem path to the backup .zip.
     * @param int|null $userId The user who triggered the restore (nullable).
     *
     * @throws RuntimeException on any failure (snapshot, extraction or import).
     */
    public function restore(string $disk, string $zipPath, ?int $userId): BackupRun
    {
        // STEP A — pre-restore safety snapshot. Must run (and succeed) FIRST.
        // If this throws, we never reach the extraction/import below and the
        // live database is left untouched.
        $this->safetySnapshot();

        $sqlPath = null;

        try {
            // STEP B — extract the .sql dump from db-dumps/ inside the zip.
            $sqlPath = $this->extractSqlDump($zipPath);

            // STEP C — import the dump into the default connection.
            $this->importDump($sqlPath);

            // STEP D — record success.
            return BackupRun::create([
                'type' => 'restore',
                'destination_disk' => $disk,
                'status' => 'success',
                'started_at' => now(),
                'finished_at' => now(),
                'triggered_by_user_id' => $userId,
                'message' => 'Restored database from ' . basename($zipPath),
            ]);
        } catch (Throwable $e) {
            // STEP D — record failure, then re-throw so the caller can surface it.
            BackupRun::create([
                'type' => 'restore',
                'destination_disk' => $disk,
                'status' => 'failed',
                'started_at' => now(),
                'finished_at' => now(),
                'triggered_by_user_id' => $userId,
                'message' => 'Restore failed: ' . $e->getMessage(),
            ]);

            throw $e instanceof RuntimeException
                ? $e
                : new RuntimeException('Restore failed: ' . $e->getMessage(), 0, $e);
        } finally {
            // Always clean up the extracted dump. The path is one we created via
            // tempnam() under sys_get_temp_dir(); re-assert that confinement
            // before unlinking so this can never be coerced into touching a file
            // outside the system temp directory.
            if ($sqlPath !== null) {
                $this->safelyDeleteTempFile($sqlPath);
            }
        }
    }

    /**
     * Delete a temporary file, but only if it really resolves to a location
     * under the system temp directory (defence-in-depth on the cleanup path).
     */
    protected function safelyDeleteTempFile(string $path): void
    {
        $real = realpath($path);
        $tmpReal = realpath(sys_get_temp_dir());

        if ($real === false || $tmpReal === false) {
            return;
        }

        if (! str_starts_with($real, rtrim($tmpReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return;
        }

        if (is_file($real)) {
            @unlink($real);
        }
    }

    /**
     * STEP A — take a fresh DB-only backup BEFORE overwriting anything.
     *
     * Isolated in its own protected method (a) so the ordering guarantee is
     * easy to reason about and (b) so tests can override it to throw and assert
     * that no import happens afterwards.
     *
     * @throws RuntimeException if the snapshot command exits non-zero.
     */
    protected function safetySnapshot(): void
    {
        $exitCode = Artisan::call('backup:run', ['--only-db' => true]);

        if ($exitCode !== 0) {
            // ABORT: do NOT touch the database when the safety net failed.
            throw new RuntimeException(
                'Pre-restore safety snapshot failed (exit code ' . $exitCode . '); restore aborted.'
            );
        }

        BackupRun::create([
            'type' => 'db',
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
            'message' => 'pre-restore safety snapshot',
        ]);
    }

    /**
     * STEP B — extract the first .sql dump found under db-dumps/ in the zip to
     * a temporary file, returning its absolute path.
     *
     * @throws RuntimeException if the zip cannot be opened or contains no dump.
     */
    protected function extractSqlDump(string $zipPath): string
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException('Backup archive not found: ' . $zipPath);
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open backup archive: ' . $zipPath);
        }

        try {
            $entry = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (str_starts_with($name, 'db-dumps/') && str_ends_with(strtolower($name), '.sql')) {
                    $entry = $name;
                    break;
                }
            }

            if ($entry === null) {
                throw new RuntimeException('No SQL dump found under db-dumps/ in the archive.');
            }

            $contents = $zip->getFromName($entry);

            if ($contents === false) {
                throw new RuntimeException('Could not read SQL dump from the archive: ' . $entry);
            }

            $sqlPath = tempnam(sys_get_temp_dir(), 'bl-restore-') . '.sql';

            if (file_put_contents($sqlPath, $contents) === false) {
                throw new RuntimeException('Could not write extracted SQL dump to a temporary file.');
            }

            return $sqlPath;
        } finally {
            $zip->close();
        }
    }

    /**
     * STEP C — import the SQL dump into the default database connection.
     *
     * Prefers the `mysql` CLI (shell-escaped credentials) when available, which
     * handles large dumps and multi-statement SQL natively; otherwise falls
     * back to DB::unprepared() with the dump contents.
     *
     * @throws RuntimeException if the import fails.
     */
    protected function importDump(string $sqlPath): void
    {
        $connection = config('database.default');
        $config = config('database.connections.' . $connection, []);
        $driver = $config['driver'] ?? null;

        if ($driver === 'mysql' && $this->mysqlClientAvailable()) {
            $this->importViaMysqlClient($sqlPath, $config);

            return;
        }

        $sql = file_get_contents($sqlPath);

        if ($sql === false) {
            throw new RuntimeException('Could not read the extracted SQL dump for import.');
        }

        DB::unprepared($sql);
    }

    /**
     * Run the dump through the `mysql` command-line client with shell-escaped
     * credentials, host, port and database name.
     *
     * @param array<string, mixed> $config
     *
     * @throws RuntimeException if the client exits non-zero.
     */
    protected function importViaMysqlClient(string $sqlPath, array $config): void
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        $binary = (new ExecutableFinder)->find('mysql', 'mysql');

        // Argument ARRAY (no shell): host/port/user/db are discrete argv entries
        // and can never be interpreted as shell syntax — there is no command
        // string to inject into. The dump is streamed via stdin; the password
        // goes through MYSQL_PWD so it never appears on the command line / `ps`.
        $command = [
            $binary,
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $username,
            $database,
        ];

        $sql = file_get_contents($sqlPath);

        if ($sql === false) {
            throw new RuntimeException('Could not read the extracted SQL dump for import.');
        }

        $process = new Process(
            $command,
            null,
            $password !== '' ? ['MYSQL_PWD' => $password] : null,
            $sql,
            3600.0
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'mysql client import failed (exit code ' . $process->getExitCode() . '): '
                . trim($process->getErrorOutput())
            );
        }
    }

    /**
     * Whether a `mysql` client binary is available on the PATH.
     */
    protected function mysqlClientAvailable(): bool
    {
        return (new ExecutableFinder)->find('mysql') !== null;
    }
}
