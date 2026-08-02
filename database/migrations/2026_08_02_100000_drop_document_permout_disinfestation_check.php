<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Sibling of 2026_08_02_090000 (which dropped the BOX PERM_OUT-disinfestation
 * CHECK). The box is authoritative for barcode status and mirrors PERM_OUT onto
 * its documents; once a box may be PERM_OUT without a disinfestation date, its
 * mirrored documents must be allowed to be PERM_OUT without one too — otherwise
 * chk_documents_permout_requires_disinfestation rejects the mirror update and
 * the whole box save fails (green on SQLite, which lacks the CHECK; broken on
 * MySQL/MariaDB where it exists). Client feedback 2026-08-01 supersedes the
 * RFQ App.1 #5 rule.
 *
 * MySQL/MariaDB-guarded; SQLite never had the CHECK.
 */
return new class extends Migration
{
    private const string CONSTRAINT = 'chk_documents_permout_requires_disinfestation';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // MariaDB: DROP CONSTRAINT IF EXISTS; MySQL 8: DROP CHECK. Try both,
        // tolerating the "already absent" case (fresh DB or re-run).
        try {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS ' . self::CONSTRAINT);

            return;
        } catch (QueryException) {
            // Older MySQL without IF EXISTS — fall through.
        }

        try {
            DB::statement('ALTER TABLE documents DROP CHECK ' . self::CONSTRAINT);
        } catch (QueryException) {
            // Constraint absent — fine.
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Re-add only if the data still satisfies it (best-effort).
        try {
            DB::statement('ALTER TABLE documents ADD CONSTRAINT ' . self::CONSTRAINT . " CHECK (barcode_status <> 'PERM_OUT' OR disinfestation_date IS NOT NULL)");
        } catch (QueryException) {
            // Data no longer satisfies it — leave the CHECK off.
        }
    }
};
