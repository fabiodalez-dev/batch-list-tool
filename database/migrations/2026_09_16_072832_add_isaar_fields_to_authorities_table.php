<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client request 2026-09-16 — ISAAR(CPF) descriptive fields on Authorities.
 *
 * The cataloguer asked for a third identifier (the warrant number) plus seven
 * descriptive fields the standard expects. Every one is nullable: the 678
 * authorities already in production predate them and must keep importing and
 * saving untouched.
 *
 * `date_of_creation` is TEXT, not a date column, deliberately — the same
 * decision as series.date_of_creation. An archival creation date is often a
 * span or an approximation ("1607-1629", "c. 1850"), and a date column would
 * either reject those or silently mangle them into a single day.
 *
 * `level_of_detail` and `status` are plain strings rather than a database ENUM.
 * The allowed values are enforced at the form and the importer, where a bad
 * value can be reported to the operator; an ENUM would reject it at the driver
 * with an error nobody can act on, and every future addition to either list
 * would need a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorities', function (Blueprint $table): void {
            $table->string('alternative_identifier_warrant', 191)->nullable()->after('alternative_identifier');
            $table->text('authorised_form_of_name')->nullable()->after('given_names');
            $table->text('functions_occupations_activities')->nullable()->after('entity_type');
            $table->string('level_of_detail', 32)->nullable()->after('functions_occupations_activities');
            $table->string('status', 32)->nullable()->after('level_of_detail');
            $table->text('rules_and_conventions')->nullable()->after('status');
            $table->text('date_of_creation')->nullable()->after('rules_and_conventions');
            $table->text('creator_of_record')->nullable()->after('date_of_creation');
        });
    }

    public function down(): void
    {
        Schema::table('authorities', function (Blueprint $table): void {
            $table->dropColumn([
                'alternative_identifier_warrant',
                'authorised_form_of_name',
                'functions_occupations_activities',
                'level_of_detail',
                'status',
                'rules_and_conventions',
                'date_of_creation',
                'creator_of_record',
            ]);
        });
    }
};
