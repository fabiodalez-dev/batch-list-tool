<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ §3.5.1 — give `box_movements` its own `repository_id` tenant key.
 *
 * Until now BoxMovement derived tenancy from a fragile 2-hop scope
 * (to_box_id → boxes.batch_id → batches.repository_id). Materialising the
 * key on the row lets the standard single-column RepositoryScope apply, and
 * removes the risk that a `Box::withoutGlobalScopes()` call elsewhere would
 * silently widen movement visibility.
 *
 * Backfill derives the value from the destination box (falling back to the
 * source box for legacy rows where to_box_id is null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('box_movements', function (Blueprint $table) {
            $table->foreignId('repository_id')
                ->nullable()
                ->after('document_id')
                ->constrained()
                ->nullOnDelete();
        });

        // Backfill: to_box_id → boxes.batch_id → batches.repository_id,
        // falling back to from_box_id when the destination box is null.
        // The correlated subquery touches only boxes/batches, so it is safe
        // on both MySQL and SQLite.
        DB::statement(<<<'SQL'
            UPDATE box_movements
               SET repository_id = (
                   SELECT b.repository_id
                     FROM boxes bx
                     JOIN batches b ON b.id = bx.batch_id
                    WHERE bx.id = COALESCE(box_movements.to_box_id, box_movements.from_box_id)
                   )
             WHERE repository_id IS NULL
        SQL);

        Schema::table('box_movements', function (Blueprint $table) {
            $table->index('repository_id');
            $table->index(['repository_id', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::table('box_movements', function (Blueprint $table) {
            $table->dropIndex(['repository_id', 'movement_date']);
            $table->dropIndex(['repository_id']);
            $table->dropConstrainedForeignId('repository_id');
        });
    }
};
