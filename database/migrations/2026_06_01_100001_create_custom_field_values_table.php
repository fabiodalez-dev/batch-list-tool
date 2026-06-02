<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_field_values')) {
            return;
        }

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_definition_id')
                ->constrained('custom_field_definitions')
                ->cascadeOnDelete();
            // Polymorphic: Document, Batch, Box, Volume
            $table->morphs('customizable');
            // All types serialized to string; cast in the model per definition type.
            // `text` (up to 65 KB on MySQL/MariaDB, unlimited on SQLite) is sufficient
            // for all v1 field types including textarea. Nullable because a row is only
            // created when the operator actually enters a value; absent = null row deleted.
            $table->text('value')->nullable();
            $table->timestamps();

            // One value per (definition, entity instance)
            $table->unique(['custom_field_definition_id', 'customizable_type', 'customizable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
    }
};
