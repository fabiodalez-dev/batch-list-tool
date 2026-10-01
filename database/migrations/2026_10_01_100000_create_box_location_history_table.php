<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ §3.1.6 — "recording and tracking the movement of boxes ... between
 * repositories, storage locations, rooms, shelves ... while preserving full
 * provenance and movement history".
 *
 * Documents have had their location history since Feedback-1 (#19); boxes
 * had none. A box moved from one shelf to another kept only its current
 * location, and the previous one was gone. Same shape as
 * document_location_history so the two trails read alike.
 *
 * No backfill: on 2026-10-01 no box in production has a location yet, and
 * the audit trail holds no box location change to rebuild from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('box_location_history', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('box_id')->constrained('boxes')->cascadeOnDelete();
            // The box's tenant (its batch's repository, or its own for a
            // batch-less box), copied at write time for the repository scope.
            $t->unsignedBigInteger('repository_id')->nullable()->index();
            // Not FK-constrained on purpose — a location may be deleted later;
            // the snapshot labels keep the trail readable when it is.
            $t->unsignedBigInteger('from_location_id')->nullable();
            $t->unsignedBigInteger('to_location_id')->nullable();
            $t->string('from_location_label')->nullable();
            $t->string('to_location_label')->nullable();
            $t->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('changed_at')->useCurrent();
            // 'create' | 'update'.
            $t->string('source', 32)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['box_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_location_history');
    }
};
