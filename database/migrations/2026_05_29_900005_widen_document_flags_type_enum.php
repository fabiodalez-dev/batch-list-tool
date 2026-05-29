<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3.1.12 — Widen the `document_flags.type` ENUM to the 15 flag types.
 *
 * The five RFQ App.2-xviii colour-code types (entry_issue, location_check,
 * not_disinfested_onsite, mould_treatment, fragment_sorted) were added to
 * App\Models\DocumentFlag::TYPES and to the create_document_flags migration.
 * Editing the create migration only helps FRESH databases — already-migrated
 * environments (e.g. the archivetool.eu staging MariaDB) keep the original
 * 10-value ENUM, so inserting a new type fails the column CHECK. This forward
 * ALTER brings every environment to the 15-value list.
 *
 * MySQL/MariaDB only; SQLite test DBs are built fresh from the (already 15-value)
 * create migration, so this is a guarded no-op there.
 */
return new class extends Migration
{
    private const TYPES = [
        'needs_review',
        'missing_data',
        'duplicate_suspect',
        'damaged',
        'restoration_needed',
        'wrongly_catalogued',
        'authority_mismatch',
        'barcode_issue',
        'disinfestation_overdue',
        'entry_issue',
        'location_check',
        'not_disinfested_onsite',
        'mould_treatment',
        'fragment_sorted',
        'other',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! Schema::hasTable('document_flags')) {
            return;
        }

        $values = collect(self::TYPES)->map(fn (string $t) => "'" . $t . "'")->implode(', ');
        DB::statement("ALTER TABLE document_flags MODIFY COLUMN type ENUM({$values}) NOT NULL");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! Schema::hasTable('document_flags')) {
            return;
        }

        $original = [
            'needs_review', 'missing_data', 'duplicate_suspect', 'damaged',
            'restoration_needed', 'wrongly_catalogued', 'authority_mismatch',
            'barcode_issue', 'disinfestation_overdue', 'other',
        ];
        $values = collect($original)->map(fn (string $t) => "'" . $t . "'")->implode(', ');
        DB::statement("ALTER TABLE document_flags MODIFY COLUMN type ENUM({$values}) NOT NULL");
    }
};
