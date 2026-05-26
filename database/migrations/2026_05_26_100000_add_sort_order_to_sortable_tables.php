<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an integer `sort_order` column to the tables operators reorder via UI.
 *
 * - boxes:     ordered WITHIN a batch (shelf layout)
 * - documents: ordered WITHIN a box   (catalogue sequence)
 * - series:    ordered globally       (rare changes, master data)
 *
 * Composite indexes ensure the reorder query stays cheap even on 50k+ rows.
 * Nullable on first add so existing rows are untouched; a follow-up seeder
 * back-fills with deterministic values (= id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boxes', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->nullable()->after('id');
            $table->index(['batch_id', 'sort_order'], 'boxes_batch_sort_idx');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->nullable()->after('id');
            $table->index(['current_box_id', 'sort_order'], 'documents_box_sort_idx');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->nullable()->after('id');
            $table->index('sort_order', 'series_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('boxes', function (Blueprint $table) {
            $table->dropIndex('boxes_batch_sort_idx');
            $table->dropColumn('sort_order');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_box_sort_idx');
            $table->dropColumn('sort_order');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropIndex('series_sort_idx');
            $table->dropColumn('sort_order');
        });
    }
};
