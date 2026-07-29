<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charlene's feedback (2026-07-28): "NTG Dates are not importing."
 *
 * Root cause: "NTG Dates Active" in the client's authority sheet is a YEAR
 * RANGE ("1882-1893") — exactly the same shape as "Private Practice Dates
 * Active". The schema, however, modelled NTG as a single `date` column
 * (`ntg_date`, added in Feedback1 C1.2). A range cannot be stored in a single
 * date, so the importer dumped the value into `notes` as free text and left
 * `ntg_date` NULL for every row (0 of 678 populated in production) — the field
 * the View page reads, so the operator saw nothing.
 *
 * Fix: model NTG the same way as private practice dates — two nullable integer
 * year columns (`ntg_dates_start` / `ntg_dates_end`). The empty, wrong-shaped
 * `ntg_date` column is dropped (it never held data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorities', function (Blueprint $table): void {
            if (! Schema::hasColumn('authorities', 'ntg_dates_start')) {
                $table->integer('ntg_dates_start')->nullable()->after('practice_dates_end');
            }
            if (! Schema::hasColumn('authorities', 'ntg_dates_end')) {
                $table->integer('ntg_dates_end')->nullable()->after('ntg_dates_start');
            }
        });

        // Drop the old single-date column + its index. Guarded so a partially
        // migrated environment does not error. The index is dropped first
        // (dropping a still-indexed column fails on some drivers).
        if (Schema::hasColumn('authorities', 'ntg_date')) {
            Schema::table('authorities', function (Blueprint $table): void {
                // The index name from 2026_05_31_100000_add_authority_filter_indexes.
                try {
                    $table->dropIndex('authorities_ntg_date_index');
                } catch (Throwable) {
                    // Index may not exist (fresh/partial env) — ignore.
                }
                $table->dropColumn('ntg_date');
            });
        }
    }

    public function down(): void
    {
        Schema::table('authorities', function (Blueprint $table): void {
            if (! Schema::hasColumn('authorities', 'ntg_date')) {
                $table->date('ntg_date')->nullable()->after('practice_dates_end');
            }
        });

        Schema::table('authorities', function (Blueprint $table): void {
            foreach (['ntg_dates_start', 'ntg_dates_end'] as $col) {
                if (Schema::hasColumn('authorities', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
