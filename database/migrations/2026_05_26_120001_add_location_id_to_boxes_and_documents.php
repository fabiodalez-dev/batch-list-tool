<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ §3.1.9 — wire the new Location lookup table to both Box and Document
 * (the RFQ is explicit: "at both box level and document level").
 *
 * The legacy free-text columns `documents.nra_location` and
 * `documents.museum_location` are intentionally NOT dropped here — they're
 * still populated by the POC import path and form part of the schema-parity
 * contract pinned by the seeder/import tests. From this PR onwards
 * `location_id` is the new source of truth; a follow-up migration will
 * back-fill and eventually retire the legacy strings.
 *
 * FK is nullOnDelete because deleting a Location must not cascade and orphan
 * a Box/Document — the Filament resource also refuses to delete a Location
 * that has any attached record (defence in depth at the application layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boxes', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('barcode_status')
                ->constrained('locations')
                ->nullOnDelete();
            $table->index(['location_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('current_box_id')
                ->constrained('locations')
                ->nullOnDelete();
            $table->index(['location_id']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // dropForeign first, then dropColumn (SQLite is tolerant; MySQL is not).
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::table('boxes', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
