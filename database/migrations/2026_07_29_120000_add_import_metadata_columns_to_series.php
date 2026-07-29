<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's Series sheet carries three columns the importer previously
 * dropped because the model had no field for them: "Level of description",
 * "Date of creation" and "Name of Inputter". They are constant in the current
 * sample, but that is the client's data to keep — silently dropping it is wrong
 * (a future sheet may vary them per series), so they are now stored verbatim.
 *
 * All nullable free-text (no lossy coercion): "Date of creation" may arrive as
 * a real date, an Excel serial, or an ISAD year range like "1607-1629", so it
 * is kept as a string. These are informational metadata; they do not replace
 * the audit-derived Inputter column or the record's own created_at timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            if (! Schema::hasColumn('series', 'level_of_description')) {
                $table->string('level_of_description', 100)->nullable()->after('description');
            }
            if (! Schema::hasColumn('series', 'date_of_creation')) {
                $table->string('date_of_creation', 100)->nullable()->after('level_of_description');
            }
            if (! Schema::hasColumn('series', 'name_of_inputter')) {
                $table->string('name_of_inputter', 255)->nullable()->after('date_of_creation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            foreach (['level_of_description', 'date_of_creation', 'name_of_inputter'] as $col) {
                if (Schema::hasColumn('series', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
