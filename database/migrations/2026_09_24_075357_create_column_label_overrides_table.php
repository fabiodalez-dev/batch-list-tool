<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client request 2026-09-24 — rename built-in columns without a release.
 *
 * The cataloguer has renamed Authority columns three times in nine days, each
 * time costing a code change, a pull request and a deploy. Her own words: "It
 * is becoming obvious that we are still renaming/adding new columns. The
 * columns are often not dependent on other parts."
 *
 * Adding columns was already solved — custom_field_definitions does that.
 * Renaming a BUILT-IN column was not, and that is what this table holds: one
 * row per renamed field, keyed the same way custom fields are (repository +
 * entity type + field), so both live side by side in the same admin screen.
 *
 * Only the LABEL is configurable. The underlying field key, its type and its
 * validation stay in code: renaming "Warrant Number" does not change what the
 * column means or which rules it obeys, which is exactly why it is safe to
 * hand over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('column_label_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repository_id')
                ->constrained('repositories')
                ->cascadeOnDelete();
            // Matches CustomFieldDefinition::ENTITY_TYPES plus the entities
            // that carry renameable built-ins (authority today).
            $table->string('entity_type', 16);
            // The importer/model field name, e.g. "alternative_identifier_warrant".
            $table->string('field_key', 64);
            $table->string('label', 128);
            $table->timestamps();

            // One override per field per entity per repository. Short explicit
            // name: the auto-generated one would exceed MariaDB's 64-char
            // identifier limit, as it did on custom_field_definitions.
            $table->unique(['repository_id', 'entity_type', 'field_key'], 'clo_repo_entity_field_unique');
            $table->index(['repository_id', 'entity_type'], 'clo_repo_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('column_label_overrides');
    }
};
