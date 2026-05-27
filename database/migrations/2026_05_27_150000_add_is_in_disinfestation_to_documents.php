<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `documents.is_in_disinfestation` — tracks whether a document is
 * CURRENTLY out for disinfestation (between the "Send to disinfestation"
 * bulk action and the "Mark disinfested" action).
 *
 * The existing `disinfestation_date` column answers "has this document ever
 * been disinfested?" but not "is it presently in the fumigation chamber?".
 * Operators need that second signal to filter the list view and run a single
 * bulk-mark-disinfested at the end of the cycle.
 *
 * Workflow:
 *   1. Operator selects N documents → "Send to disinfestation" bulk action
 *      → `is_in_disinfestation = true`, `barcode_status = 'OUT'`.
 *   2. Operator filters the table by `is_in_disinfestation = true` to see
 *      what is currently out.
 *   3. Operator selects the (now-returned) batch → "Mark disinfested"
 *      → `is_in_disinfestation = false`, `barcode_status = 'IN'`,
 *      `disinfestation_date = today()`.
 *
 * Indexed because the filter on the list view is one of the most common
 * predicates once the workflow is in steady use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->boolean('is_in_disinfestation')
                ->default(false)
                ->after('disinfestation_date')
                ->comment('Document is currently out for disinfestation (between "Send" and "Mark disinfested" actions).');
            $table->index('is_in_disinfestation', 'documents_is_in_disinfestation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex('documents_is_in_disinfestation_idx');
            $table->dropColumn('is_in_disinfestation');
        });
    }
};
