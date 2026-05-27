<?php

declare(strict_types=1);

use App\Filament\Actions\Documents\MoveToWillsAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the globally-unique constraint on `batches.batch_number` with a
 * composite unique on `(batch_number, repository_id)`.
 *
 * Rationale (review C-1 of PR #48): {@see MoveToWillsAction}
 * — and the broader RFQ §3.5.1 multi-tenant model — assumes that every
 * repository owns its own Batch 50 (and, more generally, its own batch
 * numbers). The original schema had a single-column unique on `batch_number`,
 * so the *first* tenant to create Batch 50 won the row globally and every
 * subsequent tenant hit `SQLSTATE 23000 Duplicate entry '50' for key
 * 'batches_batch_number_unique'`. This migration fixes the schema to match
 * the per-tenant invariant.
 *
 * The composite index name is set explicitly so the down() migration can
 * drop it deterministically on every driver (MySQL auto-names indexes from
 * the column list, SQLite uses a different convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table): void {
            // Drop the legacy global unique. The original index name is
            // `batches_batch_number_unique` — Laravel's default convention,
            // and verified on both MySQL and SQLite via Schema::getIndexes().
            $table->dropUnique(['batch_number']);

            // Per-tenant uniqueness. This is the correct invariant per
            // RFQ §3.5.1: every repository numbers its batches independently.
            $table->unique(
                ['batch_number', 'repository_id'],
                'batches_batch_number_repository_id_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table): void {
            $table->dropUnique('batches_batch_number_repository_id_unique');
            $table->unique('batch_number');
        });
    }
};
