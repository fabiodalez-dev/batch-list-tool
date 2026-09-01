<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema audit (#11, Low): EntityResolver::resolveInSituBox filters
 * `repository_id = ? AND box_type = ? AND box_number = ?` but boxes only had a
 * single-column repository_id index to lean on. Add the covering composite so
 * the IN_SITU box lookup during import is index-served. (The RAS path is
 * already covered by the existing (batch_id, box_number) index.)
 */
return new class extends Migration
{
    private const string INDEX = 'boxes_repo_type_number_index';

    public function up(): void
    {
        if (Schema::hasIndex('boxes', self::INDEX)) {
            return;
        }

        Schema::table('boxes', function (Blueprint $table): void {
            $table->index(['repository_id', 'box_type', 'box_number'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('boxes', self::INDEX)) {
            return;
        }

        Schema::table('boxes', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
