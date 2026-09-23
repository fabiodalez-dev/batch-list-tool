<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client request 2026-09-22 — one more identifier on Authorities, asked for
 * just before the final sheet is imported: "Previous Temporary Identifiers",
 * placed after the warrant number.
 *
 * TEXT rather than a short string: the label is plural, so a record may carry
 * several superseded identifiers, and there is no length worth enforcing on
 * free-form historic codes.
 *
 * No index and no foreign key — the cataloguer confirmed it holds no
 * relationship to anything else ("No relation. Just another type of
 * identifier"). The three identifiers that DO have to stay unique or
 * searchable already have their own columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorities', function (Blueprint $table): void {
            $table->text('previous_temporary_identifiers')
                ->nullable()
                ->after('alternative_identifier_warrant');
        });
    }

    public function down(): void
    {
        Schema::table('authorities', function (Blueprint $table): void {
            $table->dropColumn('previous_temporary_identifiers');
        });
    }
};
