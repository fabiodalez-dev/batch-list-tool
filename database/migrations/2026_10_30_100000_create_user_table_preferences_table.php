<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-10-30: "Is it possible to keep the order for the particular
 * user after logging out. So the order is associated with the user?"
 *
 * Filament keeps a list's column order and hidden columns in the session
 * only. One row per user and list holds that layout so it survives logout.
 * A table of its own, not a JSON column on users: the users table is
 * audited, and every drag of a column would have written an audit row and
 * moved updated_at. A new, empty table: nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_table_preferences', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Filament's session key for the table ("tables.<md5 of the page
            // class>[.<md5 of the table identifier>]"), at most 72 characters.
            $t->string('table_key', 100);
            $t->json('columns');
            $t->boolean('has_reordered')->default(false);
            $t->timestamps();

            $t->unique(['user_id', 'table_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_table_preferences');
    }
};
