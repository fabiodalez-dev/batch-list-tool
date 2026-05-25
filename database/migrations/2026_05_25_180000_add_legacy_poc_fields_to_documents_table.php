<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring the documents table to feature parity with the legacy POC schema
 * (Batch_List_Tool/php-backend/database/schema.mysql.sql).
 *
 * The new domain model (BoxMovement, BarcodeChange) will populate normalized
 * tables going forward, BUT we keep the denormalised POC columns alongside so:
 *   (a) the existing UI can show every field the user is used to;
 *   (b) the legacy migration data lands here verbatim;
 *   (c) we can deprecate fields one by one rather than in a big-bang move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Legacy batch / box text columns (denormalised, preserved for read parity with POC)
            $table->string('ras_batch_1', 50)->nullable()->after('repository_id');
            $table->string('ras_box_1', 50)->nullable()->after('ras_batch_1');
            $table->string('ras_batch_2', 50)->nullable()->after('ras_box_1');
            $table->string('ras_box_2', 50)->nullable()->after('ras_batch_2');
            $table->string('in_situ_box_1', 50)->nullable()->after('ras_box_2');
            $table->string('in_situ_box_2', 50)->nullable()->after('in_situ_box_1');
            $table->string('in_situ_box_3', 50)->nullable()->after('in_situ_box_2');
            $table->string('ras_1_box_destroyed', 10)->nullable()->after('in_situ_box_3');
            $table->string('ras_2_box_destroyed', 10)->nullable()->after('ras_1_box_destroyed');
            $table->string('in_situ_box_1_destroyed', 10)->nullable()->after('ras_2_box_destroyed');
            $table->string('in_situ_box_2_destroyed', 10)->nullable()->after('in_situ_box_1_destroyed');
            $table->string('in_situ_box_3_destroyed', 10)->nullable()->after('in_situ_box_2_destroyed');

            // Legacy barcode tracking
            $table->string('barcode_in', 50)->nullable()->after('in_situ_box_3_destroyed')->index();
            $table->string('barcode_ras_1', 50)->nullable()->after('barcode_in');
            $table->string('status_1', 20)->nullable()->after('barcode_ras_1');
            $table->string('barcode_ras_2', 50)->nullable()->after('status_1');
            $table->string('status_2', 20)->nullable()->after('barcode_ras_2');
            $table->string('barcode_ras_3', 50)->nullable()->after('status_2');
            $table->string('status_3', 20)->nullable()->after('barcode_ras_3');
            $table->string('barcode_ras_4', 50)->nullable()->after('status_3');
            $table->string('status_4', 20)->nullable()->after('barcode_ras_4');
            $table->string('barcode_in_2', 50)->nullable()->after('status_4');
            $table->string('barcode_ras_2_alt', 50)->nullable()->after('barcode_in_2');
            $table->string('status_1_alt', 20)->nullable()->after('barcode_ras_2_alt');
            $table->string('barcode_ras_2_alt2', 50)->nullable()->after('status_1_alt');
            $table->string('status_2_alt', 20)->nullable()->after('barcode_ras_2_alt2');

            // Seal + disinfestation history (POC kept 3 dates)
            $table->string('seal_number', 50)->nullable()->after('status_2_alt');
            $table->date('disinfestation_date_1')->nullable()->after('seal_number');
            $table->date('disinfestation_date_2')->nullable()->after('disinfestation_date_1');
            $table->date('disinfestation_date_3')->nullable()->after('disinfestation_date_2');

            // Identifiers / locations (free-form text: use TEXT to avoid length truncation on legacy data)
            $table->string('catalogue_identifier', 191)->nullable()->after('disinfestation_date_3')->index();
            $table->text('nra_location')->nullable()->after('catalogue_identifier');
            $table->text('museum_location')->nullable()->after('nra_location');
            $table->string('practice', 100)->nullable()->after('museum_location');

            // Catalogue / cataloguing data
            $table->string('dates', 191)->nullable()->after('practice');
            $table->text('deeds')->nullable()->after('dates');
            $table->string('current_box_type', 50)->nullable()->after('deeds');
            $table->string('colour_code', 32)->nullable()->after('current_box_type')->index();
            $table->string('digitised', 100)->nullable()->after('colour_code');
            $table->boolean('torre')->default(false)->after('digitised');

            // Accession + tracking
            $table->string('accession_code_legacy', 191)->nullable()->after('torre');
            $table->text('object_reference_number')->nullable()->after('accession_code_legacy');
            $table->text('tracking')->nullable()->after('object_reference_number');
            $table->text('museum_reference')->nullable()->after('tracking');

            // POC also had custom_fields json + metadata json; we already have `extra` JSON.
            // Add metadata as a second JSON bucket for full parity.
            $table->json('custom_fields')->nullable()->after('museum_reference');
            $table->json('metadata')->nullable()->after('custom_fields');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['barcode_in']);
            $table->dropIndex(['catalogue_identifier']);
            $table->dropIndex(['colour_code']);
            $table->dropColumn([
                'ras_batch_1', 'ras_box_1', 'ras_batch_2', 'ras_box_2',
                'in_situ_box_1', 'in_situ_box_2', 'in_situ_box_3',
                'ras_1_box_destroyed', 'ras_2_box_destroyed',
                'in_situ_box_1_destroyed', 'in_situ_box_2_destroyed', 'in_situ_box_3_destroyed',
                'barcode_in', 'barcode_ras_1', 'status_1', 'barcode_ras_2', 'status_2',
                'barcode_ras_3', 'status_3', 'barcode_ras_4', 'status_4',
                'barcode_in_2', 'barcode_ras_2_alt', 'status_1_alt',
                'barcode_ras_2_alt2', 'status_2_alt',
                'seal_number', 'disinfestation_date_1', 'disinfestation_date_2', 'disinfestation_date_3',
                'catalogue_identifier', 'nra_location', 'museum_location', 'practice',
                'dates', 'deeds', 'current_box_type', 'colour_code', 'digitised', 'torre',
                'accession_code_legacy', 'object_reference_number', 'tracking', 'museum_reference',
                'custom_fields', 'metadata',
            ]);
        });
    }
};
