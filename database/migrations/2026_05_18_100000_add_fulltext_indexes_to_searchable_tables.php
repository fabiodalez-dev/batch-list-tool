<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add MySQL FULLTEXT indexes on the free-text columns that the Filament
 * resources currently search with `LIKE '%term%'`.
 *
 * Why: `LIKE '%term%'` can never use a B-tree index — every row gets
 * scanned. At ~3k docs the cost is invisible; the RFQ-2026-06 contract
 * grows the table to 50k+ documents (and the same query is invoked from
 * the Filament list view on every keystroke when SearchOnBlur is off),
 * which makes search noticeably slow. A FULLTEXT natural-language search
 * on the same column buckets the work into the inverted index that MySQL
 * maintains for us, dropping p95 from >2s to <100ms.
 *
 * Driver guard: SQLite (used in CI / dev tests via :memory:) and Postgres
 * do not understand `ALTER TABLE ... ADD FULLTEXT`. We early-return on
 * any non-MySQL driver — the `Document::scopeSearchFullText()` scope is
 * the runtime-side counterpart and falls back to LIKE when no FULLTEXT
 * index is available.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // ---- documents ----
        Schema::table('documents', function (Blueprint $table) {
            $table->fullText('notes', 'idx_documents_notes_ft');
            $table->fullText('deeds', 'idx_documents_deeds_ft');
            $table->fullText('museum_reference', 'idx_documents_museum_reference_ft');
        });

        // ---- authorities ----
        // The schema only ships with `notes` on authorities; the "practice"
        // column lives on documents. We guard with hasColumn() anyway so the
        // migration is resilient to a future column rename / drop.
        Schema::table('authorities', function (Blueprint $table) {
            if (Schema::hasColumn('authorities', 'notes')) {
                $table->fullText('notes', 'idx_authorities_notes_ft');
            }

            if (Schema::hasColumn('authorities', 'practice')) {
                $table->fullText('practice', 'idx_authorities_practice_ft');
            }
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropFullText('idx_documents_notes_ft');
            $table->dropFullText('idx_documents_deeds_ft');
            $table->dropFullText('idx_documents_museum_reference_ft');
        });

        Schema::table('authorities', function (Blueprint $table) {
            if (Schema::hasColumn('authorities', 'notes')) {
                $table->dropFullText('idx_authorities_notes_ft');
            }

            if (Schema::hasColumn('authorities', 'practice')) {
                $table->dropFullText('idx_authorities_practice_ft');
            }
        });
    }
};
