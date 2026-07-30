<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Charlene (2026-07-29): the "Unknown" and "NULL" catch-all batches still failed
 * to import with *"The value 'NULL' is not a valid DECIMAL for its column"*,
 * even though batch_number was already widened to varchar in
 * 2026_07_29_100000. The culprit is the forbidden-numbers CHECK, re-added there
 * (and originally in 2026_05_29_900001) with NUMERIC literals:
 *
 *     CHECK (batch_number NOT IN (34, 36))
 *
 * With batch_number now a VARCHAR, MySQL/MariaDB coerces the string to a DECIMAL
 * to compare it against 34/36 — and a non-numeric value like "Unknown"/"NULL"
 * cannot be coerced, so in strict mode the row is REJECTED with error 1292
 * ("Truncated incorrect DECIMAL value"). The regression tests never caught this
 * because the CHECK is MySQL-only (SQLite does not enforce a named CHECK added
 * via ALTER), so the SQLite test suite happily imported the same rows.
 *
 * Fix: compare against STRING literals, so the check is a plain string
 * comparison with no numeric coercion:
 *
 *     CHECK (batch_number NOT IN ('34', '36'))
 *
 * "Unknown"/"NULL" pass (they are not the strings '34'/'36'); the numeric
 * forbidden values "34"/"36" are still rejected. MySQL/MariaDB only — SQLite is
 * a no-op, mirroring the earlier CHECK migrations.
 */
return new class extends Migration
{
    private const CHECK = 'chk_batches_forbidden_numbers';

    public function up(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        DB::statement('ALTER TABLE batches DROP CONSTRAINT IF EXISTS ' . self::CHECK);
        DB::statement('ALTER TABLE batches ADD CONSTRAINT ' . self::CHECK . " CHECK (batch_number NOT IN ('34', '36'))");
    }

    public function down(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        DB::statement('ALTER TABLE batches DROP CONSTRAINT IF EXISTS ' . self::CHECK);
        DB::statement('ALTER TABLE batches ADD CONSTRAINT ' . self::CHECK . ' CHECK (batch_number NOT IN (34, 36))');
    }

    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
