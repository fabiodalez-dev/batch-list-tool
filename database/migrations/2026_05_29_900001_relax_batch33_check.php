<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A1.1 — Relax the batches forbidden-numbers CHECK constraint.
 *
 * RFQ Appendix 2 clarification:
 *   - Batch 34 and 36: unused and will NEVER be used → still forbidden.
 *   - Batch 33: reserved for OLD MAV boxes → VALID number, NOT forbidden.
 *
 * The original migration added:
 *   CONSTRAINT chk_batches_forbidden_numbers CHECK (batch_number NOT IN (33, 34, 36))
 *
 * This migration replaces it with:
 *   CONSTRAINT chk_batches_forbidden_numbers CHECK (batch_number NOT IN (34, 36))
 *
 * The guard `if mysql` is intentional: tests run on SQLite which doesn't enforce
 * named CHECK constraints — no-op there, which is correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE batches DROP CHECK chk_batches_forbidden_numbers');
        DB::statement('ALTER TABLE batches ADD CONSTRAINT chk_batches_forbidden_numbers CHECK (batch_number NOT IN (34, 36))');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE batches DROP CHECK chk_batches_forbidden_numbers');
        DB::statement('ALTER TABLE batches ADD CONSTRAINT chk_batches_forbidden_numbers CHECK (batch_number NOT IN (33, 34, 36))');
    }
};
