<?php

use App\Models\LocationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback1 gaps — promote the hard-coded Location::CANONICAL_TYPES const
 * into an editable lookup table (client: "I couldn't find where I can
 * edit/delete/insert the Location Types. At the moment this should include:
 * Room, Museum, Repository").
 *
 * Mirrors the 2026_05_30_910100_create_lookup_tables.php pattern: the table
 * is seeded INSIDE the migration (firstOrCreate → idempotent) so production
 * gets the three canonical rows on deploy without a separate seeder run.
 *
 * Cross-engine (MariaDB 10.11 + SQLite): plain Blueprint types only, and the
 * unique index gets a SHORT explicit name (MariaDB 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('location_types')) {
            Schema::create('location_types', function (Blueprint $table): void {
                $table->id();
                $table->string('code');
                $table->string('label');
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique('code', 'loc_type_code_uq');
            });
        }

        $this->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('location_types');
    }

    /**
     * Seed the three canonical location types. firstOrCreate keeps the seed
     * idempotent (re-running the migration, or a fresh migrate on a DB that
     * already carries admin-edited rows, never duplicates or overwrites).
     */
    private function seed(): void
    {
        $canonical = [
            'room' => 'Room',
            'museum' => 'Museum',
            'repository' => 'Repository',
        ];

        $order = 0;
        foreach ($canonical as $code => $label) {
            LocationType::query()->firstOrCreate(
                ['code' => $code],
                [
                    'label' => $label,
                    'sort_order' => $order,
                    'is_active' => true,
                ],
            );
            $order++;
        }
    }
};
