<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client, 2026-09-25: "The Citing Reference Code is the primary key as it is
 * failing to import" and "The NAM Authority Reference Code is optional".
 *
 * Her file says the same thing more plainly than any argument could: across 676
 * creators, the Citing Reference Code is filled in 676 times and every value is
 * distinct, while the NAM code is filled in 80 times. The schema had it the
 * other way round — `identifier` NOT NULL UNIQUE, `alternative_identifier`
 * nullable and not unique — so 596 rows failed on a required field that the
 * archive does not have a value for.
 *
 * This only moves the CONSTRAINTS. Neither column changes meaning, nothing is
 * copied between them, and no row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Refuse rather than corrupt: a duplicate would be silently collapsed
        // by the unique index, and two creators becoming one is not something
        // anyone would notice until much later.
        $duplicates = DB::table('authorities')
            ->select('alternative_identifier')
            ->whereNotNull('alternative_identifier')
            ->where('alternative_identifier', '<>', '')
            ->groupBy('alternative_identifier')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('alternative_identifier')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot make alternative_identifier unique: these values appear on more than one authority — '
                . implode(', ', array_slice($duplicates, 0, 20))
                . (count($duplicates) > 20 ? ' …' : '')
                . '. Resolve the duplicates first, then re-run the migration.'
            );
        }

        Schema::table('authorities', function (Blueprint $table) {
            // The NAM code is now optional. The unique index stays: where a
            // value IS given it must still be the only one, and both MySQL and
            // SQLite allow any number of NULLs under a unique index.
            $table->string('identifier', 32)->nullable()->change();
        });

        Schema::table('authorities', function (Blueprint $table) {
            $table->unique('alternative_identifier', 'authorities_alt_identifier_unique');
        });
    }

    public function down(): void
    {
        Schema::table('authorities', function (Blueprint $table) {
            $table->dropUnique('authorities_alt_identifier_unique');
        });

        // Deliberately NOT restoring NOT NULL on identifier: by the time this
        // is rolled back there may be authorities that legitimately have no NAM
        // code, and the migration would fail on them. Making a column stricter
        // on the way down is how a rollback turns into an outage.
    }
};
