<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave B / B2 — Copy existing accessions.batch_id FK values into the new
 * accession_batch pivot table.
 *
 * Uses raw DB queries intentionally (no Eloquent models) to avoid model-event
 * and global-scope side-effects that could cause failures or unintended writes
 * during the migration.
 *
 * Guard: only runs if the accessions.batch_id column still exists (idempotent
 * on installations that already had this migration run in a previous deploy).
 */
return new class extends Migration
{
    public function up(): void
    {
        // If the legacy column is already gone, nothing to migrate.
        if (! Schema::hasColumn('accessions', 'batch_id')) {
            return;
        }

        // If the pivot table does not exist yet, skip (shouldn't happen in
        // normal migration order, but guards against partial deploys).
        if (! Schema::hasTable('accession_batch')) {
            return;
        }

        // Insert a pivot row for every accession that had a non-null batch_id.
        // INSERT IGNORE / ON CONFLICT DO NOTHING is engine-specific, so we
        // select rows that are NOT already in the pivot and only insert those
        // (idempotent, safe to run multiple times).
        // Include soft-deleted accessions: if they are later restored the pivot
        // link must still be present, since the batch_id column is about to be
        // dropped and cannot be used for reconstruction after this migration.
        $rows = DB::table('accessions')
            ->whereNotNull('batch_id')
            ->select(['id as accession_id', 'batch_id'])
            ->get();

        $now = now()->toDateTimeString();

        foreach ($rows as $row) {
            $exists = DB::table('accession_batch')
                ->where('accession_id', $row->accession_id)
                ->where('batch_id', $row->batch_id)
                ->exists();

            if (! $exists) {
                DB::table('accession_batch')->insert([
                    'accession_id' => $row->accession_id,
                    'batch_id' => $row->batch_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Reversing a data migration on a single-install system is not safe:
        // we cannot know which pivot rows existed before vs. were added here.
        // The corresponding schema migration (B3) handles column restoration.
    }
};
