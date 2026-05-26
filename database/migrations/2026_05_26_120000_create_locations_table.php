<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ §3.1.9 — Configurable Location Hierarchies.
 *
 * The legacy POC stored location as two free-text strings on the document
 * (`documents.nra_location` and `documents.museum_location`). The RFQ
 * mandates a *configurable* hierarchy that can attach to BOTH a Box and a
 * Document. This migration introduces the `locations` lookup table.
 *
 * Modelling choice — Adjacency List + materialised path.
 *  - `parent_id` carries the tree edge (Adjacency List) and is FK-constrained
 *    to keep referential integrity. Cycles are prevented by the LocationObserver.
 *  - `path` carries the materialised path of ancestor ids ("1/4/12") so that
 *    descendants("LIKE '1/4/%'") is a single index scan instead of N recursive
 *    joins. The observer keeps it in sync on every save.
 *  - `depth` is cached for fast filtering ("only top-level repositories" etc.)
 *    and as a defence-in-depth cap against runaway recursion (capped at 6).
 *
 * `repository_id` is NULLABLE on purpose: certain locations are global by
 * nature (e.g. an off-site "Conservation Lab" shared between repositories,
 * or a generic "Temporary Holding Area"). The unique constraint on
 * (repository_id, code) treats global rows as their own tenancy bucket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();
            $table->string('name', 100);
            $table->string('code', 32)->nullable();
            // Stored as VARCHAR rather than ENUM so test driver (SQLite) and
            // production (MySQL) behave identically. Application-level
            // whitelist lives in Location::TYPES.
            $table->string('type', 32)->index();
            $table->foreignId('repository_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete()
                ->comment('multi-tenant scope; null = global');
            // Materialised path of ancestor ids ("1/4/12") — recomputed by
            // LocationObserver on save. NULL for root nodes.
            $table->string('path', 500)->nullable()->index();
            $table->unsignedInteger('depth')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A code, when provided, is unique within its repository scope.
            // Two repos may both have a "MAIN-VAULT" code; a global location
            // and a repo-scoped location MAY share a code (NULL ≠ value
            // under standard SQL unique semantics on both MySQL and SQLite).
            $table->unique(['repository_id', 'code']);
            $table->index(['repository_id', 'parent_id']);
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
