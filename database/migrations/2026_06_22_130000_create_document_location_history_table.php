<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NAF Feedback-1 (Documents page, comment #19) — "we have no history of
 * locations, just the current location." Append-only log of a document's
 * `location_id` transitions. Mirrors document_barcode_history.
 *
 * We snapshot the location *label* (breadcrumb) at the time of the change so the
 * history stays meaningful even after a Location is later renamed, re-parented
 * or deleted — the from/to ids are kept too but are not FK-constrained for that
 * reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_location_history')) {
            return;
        }

        Schema::create('document_location_history', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            // Mirrors document_barcode_history: repository taken from the
            // document's own column (no FK constraint, matches that table).
            $t->unsignedBigInteger('repository_id')->nullable()->index();
            // Not FK-constrained on purpose — a location may be deleted later;
            // the snapshot label below preserves the human-readable value.
            $t->unsignedBigInteger('from_location_id')->nullable();
            $t->unsignedBigInteger('to_location_id')->nullable();
            $t->string('from_location_label')->nullable();
            $t->string('to_location_label')->nullable();
            $t->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('changed_at')->useCurrent();
            // Best-effort provenance: 'create' | 'update' (extendable).
            $t->string('source', 32)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_location_history');
    }
};
