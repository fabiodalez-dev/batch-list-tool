<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ §3.1.8 — persisted overrides for the field-level permission matrix.
 *
 * `config/field_permissions.php` remains the baseline (version-controlled,
 * diff-reviewed). This table lets an Administrator "review and adjust" the
 * matrix from the UI (the submission's wording): each row fully replaces the
 * read / write / hidden_from lists for one (resource, field) pair, taking
 * precedence over the config baseline. Absence of a row = use the config.
 *
 * One row per (resource, field); `_default` is stored as the field name to
 * override a resource's fallback block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('resource', 64);
            $table->string('field', 64);
            $table->json('read')->nullable();
            $table->json('write')->nullable();
            $table->json('hidden_from')->nullable();
            $table->timestamps();

            $table->unique(['resource', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_permission_overrides');
    }
};
