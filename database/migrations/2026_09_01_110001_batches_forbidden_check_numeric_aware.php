<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Schema audit (#19, Low — defense-in-depth).
 *
 * The forbidden-numbers CHECK on batches is `batch_number NOT IN ('34','36')`
 * — a pure string comparison after batch_number became VARCHAR, so numeric
 * respellings ('034', '0034') denote 34/36 yet pass the DB net. The app guards
 * (Batch::isAcceptableNumberFormat / isForbidden) reject them at every ingress,
 * so this only matters for a raw/bulk DB write. Make the CHECK numeric-aware
 * with a leading-zero-tolerant pattern; MySQL/MariaDB only (SQLite does not
 * enforce a named CHECK, so this is a no-op there — mirrors the earlier CHECK
 * migrations).
 */
return new class extends Migration
{
    private const string CHECK = 'chk_batches_forbidden_numbers';

    public function up(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        DB::statement('ALTER TABLE batches DROP CONSTRAINT IF EXISTS ' . self::CHECK);
        // ^0*(34|36)$ matches 34/36 and any leading-zero respelling ('034',
        // '0036') while leaving every other batch number valid.
        DB::statement('ALTER TABLE batches ADD CONSTRAINT ' . self::CHECK . " CHECK (batch_number NOT REGEXP '^0*(34|36)$')");
    }

    public function down(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        DB::statement('ALTER TABLE batches DROP CONSTRAINT IF EXISTS ' . self::CHECK);
        DB::statement('ALTER TABLE batches ADD CONSTRAINT ' . self::CHECK . " CHECK (batch_number NOT IN ('34', '36'))");
    }

    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
