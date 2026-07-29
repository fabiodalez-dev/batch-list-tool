<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Charlene's feedback (2026-07-28): two batches whose batch_number is NOT a
 * number — "Unknown" (Documents with unknown origin) and "NULL" (Documents
 * never packed in boxes) — fail to import because `batch_number` is an
 * integer column.
 *
 * These are legitimate catch-all batches the archive needs. batch_number is
 * widened from an unsigned integer to a short string so non-numeric identifiers
 * import and display verbatim. The composite unique key (batch_number,
 * repository_id) is preserved, and so is the RFQ Appendix 2 forbidden-numbers
 * CHECK — `batch_number NOT IN (34, 36)` still forbids the numeric strings "34"
 * / "36" via the DB's implicit cast, while a non-numeric label ("Unknown") is
 * never equal to 34 or 36, so it passes.
 *
 * The model helpers (isForbidden / isReservedMav / isWillsOnly) cast to int
 * before comparing, so the RFQ reserved-number rules keep applying to numeric
 * batch numbers only. Existing integer values convert to their decimal string
 * form ("1", "50", …) — no data loss; numeric ordering is preserved via a
 * `batch_number + 0` sort in the queries that order by it.
 *
 * The CHECK is dropped before the column type change and re-added after (MySQL/
 * MariaDB only — SQLite does not enforce named CHECK constraints added via
 * ALTER, mirroring 2026_05_29_900001_relax_batch33_check).
 */
return new class extends Migration
{
    private const UNIQUE = 'batches_batch_number_repository_id_unique';

    private const CHECK = 'chk_batches_forbidden_numbers';

    public function up(): void
    {
        if ($this->isMysql()) {
            DB::statement('ALTER TABLE batches DROP CONSTRAINT IF EXISTS ' . self::CHECK);
        }

        // Guard with hasIndex() rather than a try/catch: inside a Schema::table
        // closure the builder DEFERS every command and runs them only after the
        // closure returns, so a try/catch around $table->dropUnique() would catch
        // nothing (the SQL executes outside the try).
        if (Schema::hasIndex('batches', self::UNIQUE)) {
            Schema::table('batches', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE);
            });
        }

        Schema::table('batches', function (Blueprint $table): void {
            $table->string('batch_number', 64)->nullable(false)->change();
        });

        Schema::table('batches', function (Blueprint $table): void {
            $table->unique(['batch_number', 'repository_id'], self::UNIQUE);
        });

        if ($this->isMysql()) {
            DB::statement('ALTER TABLE batches ADD CONSTRAINT ' . self::CHECK . ' CHECK (batch_number NOT IN (34, 36))');
        }
    }

    public function down(): void
    {
        if ($this->isMysql()) {
            DB::statement('ALTER TABLE batches DROP CONSTRAINT IF EXISTS ' . self::CHECK);
        }

        if (Schema::hasIndex('batches', self::UNIQUE)) {
            Schema::table('batches', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE);
            });
        }

        // NOTE: any non-numeric batch_number (e.g. "Unknown", "NULL") coerces to
        // 0 on the way back to an integer column — the reverse is inherently
        // lossy for the rows this migration exists to support.
        Schema::table('batches', function (Blueprint $table): void {
            $table->unsignedInteger('batch_number')->nullable(false)->change();
        });

        Schema::table('batches', function (Blueprint $table): void {
            $table->unique(['batch_number', 'repository_id'], self::UNIQUE);
        });

        if ($this->isMysql()) {
            DB::statement('ALTER TABLE batches ADD CONSTRAINT ' . self::CHECK . ' CHECK (batch_number NOT IN (34, 36))');
        }
    }

    private function isMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
