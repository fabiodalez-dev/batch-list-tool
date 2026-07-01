<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug #36 (client: "New boxes may have more than one parent box… documents from
 * different box origins are placed together into a new box").
 *
 * A box's single `parent_box_id` (and the RFQ A1.3 single-RAS-parent provenance
 * guard) stays exactly as-is — that remains the PRIMARY origin. This pivot adds
 * OPTIONAL additional parent boxes on top, so an NRA box assembled after
 * cataloguing can reference every origin (RAS) box its documents came from,
 * without weakening the existing provenance rule.
 *
 * Cross-engine (MariaDB 10.11 + SQLite): plain Blueprint types, short index name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('box_parents')) {
            return;
        }

        Schema::create('box_parents', function (Blueprint $table): void {
            $table->foreignId('box_id')->constrained('boxes')->cascadeOnDelete();
            $table->foreignId('parent_box_id')->constrained('boxes')->cascadeOnDelete();
            $table->primary(['box_id', 'parent_box_id'], 'box_parents_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_parents');
    }
};
