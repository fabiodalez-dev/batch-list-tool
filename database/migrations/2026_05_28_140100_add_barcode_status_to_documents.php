<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `documents.barcode_status` (RFQ App.1 #5 — PERM_OUT support at the
 * document level).
 *
 * Until this migration, `barcode_status` existed only on `boxes`, so the
 * MarkPermOutAction (Action #6) silently degraded to writing only an audit
 * row (review H-1 of PR #48). Now the column exists alongside Box's so the
 * document's permanent-out state is actually persisted, queryable, and
 * shown on dashboards / list filters.
 *
 * Vocabulary mirrors Box::BARCODE_STATUSES: IN | OUT | PERM_OUT.
 *
 * SQLite quirk: `enum` columns are emitted as TEXT with a CHECK constraint
 * on MySQL but as plain TEXT on SQLite. We use Laravel's Blueprint::enum()
 * which transparently does the right thing per driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->enum('barcode_status', ['IN', 'OUT', 'PERM_OUT'])
                ->default('IN')
                ->after('barcode_in')
                ->index();
        });

        // Backfill: any row that already has disinfestation_date set could
        // already be PERM_OUT in principle, but we deliberately default to
        // 'IN' for everything — the operator is the source of truth for
        // PERM_OUT decisions and we don't want this migration to silently
        // mark thousands of documents as permanently transferred out.
        // (No-op statement here for clarity; the column default handles it.)
        DB::table('documents')->whereNull('barcode_status')->update(['barcode_status' => 'IN']);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['barcode_status']);
            $table->dropColumn('barcode_status');
        });
    }
};
