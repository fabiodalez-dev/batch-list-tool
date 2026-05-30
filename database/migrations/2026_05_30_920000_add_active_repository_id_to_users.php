<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ Wave 2 Task 10 — persist the user's *active* repository selection across
 * sessions. null = "All repositories" (the EXPAND-NEVER-RESTRICT default).
 *
 * The session is the primary store (App\Support\ActiveRepository); this column
 * is a best-effort mirror so the choice survives a fresh login / new device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('active_repository_id')
                ->nullable()
                ->after('default_repository_id')
                ->constrained('repositories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_repository_id');
        });
    }
};
