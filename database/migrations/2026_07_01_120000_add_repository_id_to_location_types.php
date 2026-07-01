<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug #18 (client: "Location Type should also be associated with a repository").
 *
 * Add a NULLABLE repository_id to location_types (NULL = global type, applies to
 * every repository). Deliberately no repository global scope on the model: the
 * type codes are a controlled vocabulary referenced by Location::$type, so hiding
 * global rows would break location forms. The column is an optional association
 * the operator can set, mirroring how practices/series carry an optional
 * repository. The existing `loc_type_code_uq` (global unique on code) is kept.
 *
 * Cross-engine (MariaDB 10.11 + SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('location_types') && ! Schema::hasColumn('location_types', 'repository_id')) {
            Schema::table('location_types', function (Blueprint $table): void {
                $table->foreignId('repository_id')
                    ->nullable()
                    ->after('code')
                    ->constrained('repositories')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('location_types') && Schema::hasColumn('location_types', 'repository_id')) {
            Schema::table('location_types', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('repository_id');
            });
        }
    }
};
