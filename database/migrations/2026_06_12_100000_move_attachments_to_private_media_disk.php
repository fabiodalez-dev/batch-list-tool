<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * F032 — relocate existing `attachments` media off the public disk onto the
 * private `media` disk and flip their `media.disk` column.
 *
 * Before F032, Accession / Document attachments were stored on the `public`
 * disk (storage/app/public/{id}/{file}, world-readable via /storage). They now
 * live on the private `media` disk (storage/app/private/media/{id}/{file},
 * served only via the authenticated attachments.download route). This migration
 * moves any already-uploaded files and updates the DB so existing rows keep
 * resolving after the disk flip.
 *
 * Idempotent + cross-engine + tolerant of missing files / empty media table:
 *   - no-op when the `media` table does not exist (fresh installs run this
 *     before the table, or installs without spatie media)
 *   - only touches rows whose disk is NOT already 'media'
 *   - copies the physical file only when it exists on the source and is absent
 *     on the destination (safe to re-run; tolerates files already moved by hand
 *     or genuinely missing on disk)
 *   - uses raw DB queries (no Eloquent) to avoid model events / global scopes
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media')) {
            return;
        }

        // The spatie default path generator stores files under {media.id}/{file_name}.
        // Move attachments that still point at the public (or any non-media) disk.
        $rows = DB::table('media')
            ->where('collection_name', 'attachments')
            ->where('disk', '!=', 'media')
            ->select(['id', 'disk', 'file_name'])
            ->get();

        if ($rows->isEmpty()) {
            return; // tolerate empty / already-migrated media table
        }

        $target = Storage::disk('media');

        foreach ($rows as $row) {
            $relativeDir = (string) $row->id;
            $relativePath = $relativeDir . '/' . $row->file_name;

            $source = $this->resolveDisk((string) $row->disk);

            // Move the physical file when present on the source and not yet on
            // the destination. Missing source files are tolerated (the DB row
            // is still flipped so the app resolves the new disk consistently).
            if ($source instanceof Filesystem
                && $source->exists($relativePath)
                && ! $target->exists($relativePath)) {
                $stream = $source->readStream($relativePath);
                if ($stream !== null) {
                    // finally guarantees the handle is closed even when
                    // writeStream() throws — with many files, leaked handles
                    // would exhaust the process file-descriptor limit.
                    try {
                        $target->writeStream($relativePath, $stream);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }

                    // Best-effort cleanup of the now-public copy.
                    $source->delete($relativePath);
                }
            }

            DB::table('media')->where('id', $row->id)->update(['disk' => 'media']);
        }
    }

    public function down(): void
    {
        // Reversing is unsafe on a single-install system: we cannot reconstruct
        // which disk each attachment originally used. The files remain readable
        // from the private disk; no schema change to revert.
    }

    /**
     * Resolve a disk by name, tolerating a configuration that no longer defines
     * the legacy disk (returns null → file move is skipped, DB row still flipped).
     */
    private function resolveDisk(string $name): ?Filesystem
    {
        if (! array_key_exists($name, (array) config('filesystems.disks'))) {
            return null;
        }

        return Storage::disk($name);
    }
};
