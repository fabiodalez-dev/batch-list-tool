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
            // All types serialized to string/JSON; cast in the model
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
