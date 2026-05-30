<?php

namespace App\Support;

use App\Models\BackupDestination;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Runtime registration of user-defined backup destinations.
 *
 * Backup destinations are stored in the `backup_destinations` table (driver +
 * encrypted credentials). At boot we translate each active destination into a
 * Laravel filesystem disk config (config('filesystems.disks.<key>')) and append
 * its disk key to spatie/laravel-backup's destination disk list
 * (config('backup.backup.destination.disks')).
 *
 * This keeps credentials OUT of the static config files and lets NAF manage
 * backup targets entirely from the admin UI — no .env edits, no redeploy.
 *
 * Security: this class never logs, echoes, or returns the decrypted credentials.
 * The only place config values are used is when building the runtime disk array.
 */
class BackupDestinations
{
    /**
     * Build runtime filesystem disks for every active destination and wire them
     * into the spatie/laravel-backup disk list.
     *
     * Self-guards on the table so it is safe to call from a service provider's
     * boot() before migrations have run (fresh install, CI, etc.).
     */
    public static function register(): void
    {
        // Pre-migration safety: the table may not exist yet (fresh install,
        // `migrate:fresh` in tests before RefreshDatabase has run, etc.).
        if (! Schema::hasTable('backup_destinations')) {
            return;
        }

        foreach (BackupDestination::active()->get() as $destination) {
            self::applyDisk($destination);
            self::appendBackupDisk($destination->disk_key);
        }
    }

    /**
     * Attempt a real connectivity check against a destination's disk.
     *
     * Registers THIS destination's disk into the runtime filesystem config (DRY:
     * reuses the same applyDisk() used by register()), then writes and deletes a
     * tiny probe file. Any failure (auth, host, permissions) surfaces as
     * ok=false with the flysystem exception message.
     *
     * Security: the returned message is the flysystem/SDK exception text, which
     * is safe to surface. We never append the destination's config/credentials
     * to the message.
     *
     * @return array{ok: bool, message: string}
     */
    public static function testConnection(BackupDestination $destination): array
    {
        try {
            self::applyDisk($destination);

            $disk = Storage::disk($destination->disk_key);
            $disk->put('.bc-conn-test', 'ok');
            $disk->delete('.bc-conn-test');

            return ['ok' => true, 'message' => 'Connection OK'];
        } catch (\Throwable $e) {
            // Only the exception message — never the config — is exposed.
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Merge a single destination's filesystem disk config into the runtime
     * config under filesystems.disks.<disk_key>.
     */
    private static function applyDisk(BackupDestination $destination): void
    {
        $diskConfig = self::buildDiskConfig($destination);

        config(['filesystems.disks.' . $destination->disk_key => $diskConfig]);
    }

    /**
     * Translate a destination's driver + decrypted config into a Laravel
     * filesystem disk configuration array.
     *
     * @return array<string, mixed>
     */
    private static function buildDiskConfig(BackupDestination $destination): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $destination->config ?? [];
        $diskKey = $destination->disk_key;

        return match ($destination->driver) {
            'ftp' => [
                'driver' => 'ftp',
                'host' => $cfg['host'] ?? null,
                'username' => $cfg['username'] ?? null,
                'password' => $cfg['password'] ?? null,
                'port' => (int) ($cfg['port'] ?? 21),
                'root' => $cfg['root'] ?? '',
                'passive' => true,
                'ssl' => $cfg['ssl'] ?? false,
                'timeout' => 30,
            ],
            'sftp' => [
                'driver' => 'sftp',
                'host' => $cfg['host'] ?? null,
                'username' => $cfg['username'] ?? null,
                'password' => $cfg['password'] ?? null,
                'privateKey' => $cfg['privateKey'] ?? null,
                'passphrase' => $cfg['passphrase'] ?? null,
                'port' => (int) ($cfg['port'] ?? 22),
                'root' => $cfg['root'] ?? '',
            ],
            's3' => [
                'driver' => 's3',
                'key' => $cfg['key'] ?? null,
                'secret' => $cfg['secret'] ?? null,
                'region' => $cfg['region'] ?? null,
                'bucket' => $cfg['bucket'] ?? null,
                'root' => $cfg['root'] ?? '',
                'endpoint' => $cfg['endpoint'] ?? null,
            ],
            // 'local' and any unknown driver fall back to a local disk so a
            // misconfigured row can never reference an undefined driver.
            default => [
                'driver' => 'local',
                'root' => $cfg['root'] ?? storage_path('app/backups/' . $diskKey),
            ],
        };
    }

    /**
     * Append a disk key to spatie/laravel-backup's destination disk list,
     * deduplicating and ALWAYS keeping 'local' present so the on-disk copy is
     * never accidentally dropped.
     */
    private static function appendBackupDisk(string $diskKey): void
    {
        /** @var array<int, string> $disks */
        $disks = (array) config('backup.backup.destination.disks', ['local']);

        $disks = array_values(array_unique([
            'local',
            ...$disks,
            $diskKey,
        ]));

        config(['backup.backup.destination.disks' => $disks]);
    }
}
