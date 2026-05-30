<?php

use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * RFQ §3.1.11 (part 1 of 3) — promote the controlled vocabularies that were
 * hard-coded as PHP consts into dedicated, editable lookup tables.
 *
 * Six tables share a common base shape (code/label/sort_order/is_active/
 * metadata) plus per-table extras. Each table is seeded INSIDE this migration
 * from the very consts it replaces, using updateOrInsert() so re-running the
 * seed (or a fresh migrate) is idempotent.
 *
 * MariaDB-portable: no DB-engine-specific column types are used; `json` maps
 * to LONGTEXT on MariaDB and is handled by the Blueprint json() helper.
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        };

        Schema::create('box_types', function (Blueprint $table) use ($base): void {
            $base($table);
            $table->boolean('is_legacy')->default(false);
        });

        Schema::create('barcode_statuses', $base);

        Schema::create('flag_types', function (Blueprint $table) use ($base): void {
            $base($table);
            $table->string('colour')->nullable();
        });

        Schema::create('digitisation_statuses', $base);

        Schema::create('current_box_types', function (Blueprint $table) use ($base): void {
            $base($table);
            // RFQ App.2-ix — disinfestation weight (Big Brown Box counts as 2).
            $table->unsignedTinyInteger('counts_as')->nullable();
        });

        Schema::create('batch_types', $base);

        $this->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_types');
        Schema::dropIfExists('current_box_types');
        Schema::dropIfExists('digitisation_statuses');
        Schema::dropIfExists('flag_types');
        Schema::dropIfExists('barcode_statuses');
        Schema::dropIfExists('box_types');
    }

    /**
     * Humanize a code into a label, keeping known acronyms / short codes
     * uppercase as-is (RAS, NRA, MAV, STVC, IN, OUT, PERM_OUT, VHMML, none),
     * and headlining everything else (e.g. needs_review → Needs Review).
     */
    private function label(string $code): string
    {
        // Already a multi-word title-cased value (e.g. "RAS Box") → keep verbatim.
        if (str_contains($code, ' ')) {
            return $code;
        }

        $upper = strtoupper($code);

        // Short / acronym codes: keep verbatim except "none" which reads better
        // headlined ("None"). PERM_OUT → "PERM OUT" for display.
        $acronyms = ['RAS', 'IN_SITU', 'NRA', 'MAV', 'STVC', 'IN', 'OUT', 'PERM_OUT', 'VHMML', 'MAIN_COLLECTION', 'NOTARY_ACCESSION'];
        if (in_array($upper, $acronyms, true)) {
            return str_replace('_', ' ', $upper);
        }

        return Str::headline($code);
    }

    private function seed(): void
    {
        $now = now();

        $insert = function (string $table, string $code, array $extra = [], int $order = 0) use ($now): void {
            DB::table($table)->updateOrInsert(
                ['code' => $code],
                array_merge([
                    'label' => $this->label($code),
                    'sort_order' => $order,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ], $extra),
            );
        };

        // box_types — is_legacy from Box::LEGACY_TYPES.
        foreach (Box::TYPES as $i => $code) {
            $insert('box_types', $code, [
                'is_legacy' => in_array($code, Box::LEGACY_TYPES, true),
            ], $i);
        }

        // barcode_statuses.
        foreach (Box::BARCODE_STATUSES as $i => $code) {
            $insert('barcode_statuses', $code, [], $i);
        }

        // flag_types — colour via inverted DocumentFlag::COLOUR_TYPES (colour=>type).
        $typeToColour = array_flip(DocumentFlag::COLOUR_TYPES);
        foreach (DocumentFlag::TYPES as $i => $code) {
            $insert('flag_types', $code, [
                'colour' => $typeToColour[$code] ?? null,
            ], $i);
        }

        // digitisation_statuses.
        foreach (Document::DIGITISED_VALUES as $i => $code) {
            $insert('digitisation_statuses', $code, [], $i);
        }

        // current_box_types — counts_as: Big Brown Box weighs 2, others 1.
        foreach (Document::CURRENT_BOX_TYPES as $i => $code) {
            $insert('current_box_types', $code, [
                'counts_as' => $code === 'Big Brown Box' ? 2 : 1,
            ], $i);
        }

        // batch_types — from the batches.type enum (MAIN_COLLECTION / NOTARY_ACCESSION).
        $batchTypes = ['MAIN_COLLECTION', 'NOTARY_ACCESSION'];
        foreach ($batchTypes as $i => $code) {
            $insert('batch_types', $code, [], $i);
        }
    }
};
