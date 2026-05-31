<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance follow-up (dashboard-filters review): the Feedback1 Authority
 * filters scan columns that had no index — practice-period range, "worked as
 * NTG" (ntg_date IS [NOT] NULL), and "has MS number" (alternative_identifier).
 * On ~800 creators these were full table scans per filter apply. Add covering
 * indexes.
 *
 * Cross-engine safe: plain Schema::table (no ->after()), each index added in
 * its own try/catch so a partially-applied MariaDB re-run that already created
 * one index just skips it (MariaDB raises "Duplicate key name"); SQLite test
 * runs are fresh so they never hit the catch.
 */
return new class extends Migration
{
    /** @var array<string, string> column => index name */
    private array $indexes = [
        'practice_dates_start' => 'authorities_practice_dates_start_index',
        'practice_dates_end' => 'authorities_practice_dates_end_index',
        'ntg_date' => 'authorities_ntg_date_index',
        'alternative_identifier' => 'authorities_alternative_identifier_index',
    ];

    public function up(): void
    {
        foreach ($this->indexes as $column => $indexName) {
            if (! Schema::hasColumn('authorities', $column)) {
                continue;
            }

            try {
                Schema::table('authorities', function (Blueprint $table) use ($column, $indexName): void {
                    $table->index($column, $indexName);
                });
            } catch (Throwable) {
                // Index already present (idempotent re-run) — nothing to do.
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $column => $indexName) {
            if (! Schema::hasColumn('authorities', $column)) {
                continue;
            }

            try {
                Schema::table('authorities', function (Blueprint $table) use ($indexName): void {
                    $table->dropIndex($indexName);
                });
            } catch (Throwable) {
                // Index already absent — nothing to do.
            }
        }
    }
};
