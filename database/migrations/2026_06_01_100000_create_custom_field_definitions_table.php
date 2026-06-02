<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_field_definitions')) {
            return;
        }

        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')
                ->constrained('repositories')
                ->cascadeOnDelete();
            // One of: document|batch|box|volume
            $table->string('entity_type', 16);
            // Machine key, snake_case, used as storage/array key
            $table->string('key', 64);
            $table->string('label', 128);
            // One of: text|textarea|number|boolean|date|datetime|select|email|url
            $table->string('type', 16);
            // For select: array of {value,label}; null otherwise
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('help_text', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Each key must be unique within a repository + entity_type scope.
            // Explicit SHORT index names: the auto-generated name (table + all
            // four columns + suffix) exceeds MariaDB's 64-char identifier limit
            // (error 1059). SQLite has no such limit, so this only surfaced on
            // the prod MariaDB, not in the SQLite test suite.
            $table->unique(['repository_id', 'entity_type', 'key'], 'cfd_repo_entity_key_uq');
            // Composite index for active definitions listing by entity
            $table->index(['repository_id', 'entity_type', 'is_active', 'sort_order'], 'cfd_repo_entity_active_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_definitions');
    }
};
