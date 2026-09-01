<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema audit (#16, Low): locations.repository_id was the only non-pivot tenant
 * FK with ON DELETE CASCADE — every other tenant FK is restrictOnDelete
 * (documents/batches/accessions) or nullOnDelete (boxes/box_movements/series/
 * practices/users). A Repository soft-deletes with no re-home hook, and the
 * policy exposes forceDelete, so force-deleting a repository that still has
 * Locations (but no documents/batches/accessions to restrict it) would
 * cascade-wipe its entire materialised-path location hierarchy. Align it with
 * the other tenant FKs: SET NULL, so such a location simply becomes global
 * rather than being destroyed. (repository_id is already nullable — global
 * locations use NULL — so SET NULL is a valid, non-destructive outcome.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropForeign(['repository_id']);
            $table->foreign('repository_id')->references('id')->on('repositories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropForeign(['repository_id']);
            $table->foreign('repository_id')->references('id')->on('repositories')->cascadeOnDelete();
        });
    }
};
