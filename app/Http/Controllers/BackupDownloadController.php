<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.8 — Backup Center: authenticated download of a backup archive.
 *
 * Streams a single `.zip` backup file from one of the configured backup
 * destination disks. The route is gated to admin / super_admin and the
 * requested path is validated to prevent directory traversal:
 *
 *   - The disk must be one of config('backup.backup.destination.disks').
 *   - The path must resolve to a `.zip` file directly under the backup-name
 *     directory (no `..`, no absolute paths, no leading slash). We rebuild the
 *     safe path from the backup-name dir + basename of the request, so any
 *     traversal segment is discarded before it ever reaches the disk.
 *   - The rebuilt file must actually exist on the disk.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless((bool) $request->user()?->hasAnyRole(['super_admin', 'admin']), 403);

        $disk = (string) $request->query('disk', '');
        $path = (string) $request->query('path', '');

        /** @var array<int, string> $disks */
        $disks = config('backup.backup.destination.disks', ['local']);

        // Disk must be an explicitly configured backup destination.
        abort_unless(in_array($disk, $disks, true), 403, 'Invalid backup disk.');

        // Reject anything that is not a plain .zip file name.
        abort_unless(str_ends_with(strtolower($path), '.zip'), 403, 'Only .zip backups can be downloaded.');

        // Reject traversal / absolute paths outright (defence in depth).
        abort_if(
            str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\'),
            403,
            'Invalid backup path.'
        );

        // Rebuild a guaranteed-safe path: <backup-name>/<basename>. basename()
        // strips any directory component, so even a crafted path collapses to a
        // file inside the backup directory and can never escape it.
        $appName = (string) config('backup.backup.name', config('app.name', 'laravel-backup'));
        $safePath = trim($appName, '/') . '/' . basename($path);

        $storage = Storage::disk($disk);

        abort_unless($storage->exists($safePath), 404, 'Backup file not found.');

        return $storage->download($safePath, basename($safePath));
    }
}
