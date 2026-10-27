<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-10-27: "Although uploaded in separate columns the Name Suffix
 * was appended to the Creator Name" and "Maiden Name – not shown in
 * Authorities". Neither had a column: the suffix was glued to given_names
 * ("Cristoforo Senior") and the maiden surname became a line of the notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorities', function (Blueprint $t): void {
            $t->string('name_suffix', 64)->nullable()->after('given_names');
            $t->string('maiden_surname', 255)->nullable()->after('name_suffix');
        });
    }

    public function down(): void
    {
        Schema::table('authorities', function (Blueprint $t): void {
            $t->dropColumn(['name_suffix', 'maiden_surname']);
        });
    }
};
