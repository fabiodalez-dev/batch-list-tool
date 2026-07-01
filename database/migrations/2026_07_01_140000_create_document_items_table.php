<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NAF Queries Q5 — box itemisation.
 *
 * A single document can stand for many physical items ("71 folders" on one
 * line). This child table lets that one record be expanded into an itemised
 * list — one row per folder/item — either typed in manually or pasted/uploaded
 * from a sheet. `position` keeps the operator's ordering; `reference` is the
 * folder number/label; `description` is free text.
 *
 * Cross-engine (MariaDB 10.11 + SQLite): plain Blueprint types, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_items')) {
            return;
        }

        Schema::create('document_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->string('reference', 128)->nullable();
            $table->string('description', 512)->nullable();
            $table->timestamps();

            $table->index(['document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_items');
    }
};
