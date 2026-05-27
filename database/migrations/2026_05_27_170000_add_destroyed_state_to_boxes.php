<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ Appendix 2 §vii — "Box destroyed" workflow.
 *
 * Once a RAS box or an In Situ box has been fully catalogued (every document
 * inside it has been assigned a `catalogue_identifier`), the physical box is
 * destroyed and discarded. This migration adds the three columns that record
 * the business event:
 *
 *   - destroyed_at         when the operator marked the box destroyed
 *   - destroyed_by_user_id who confirmed the destruction
 *   - destroyed_reason     free-text "where / why" note
 *
 * The columns sit AFTER `deleted_at` on purpose: SoftDeletes already gives us
 * "row is hidden from queries", but "destroyed" is a distinct business state
 * (the physical artefact no longer exists, even though we still want the row
 * visible in the archive for provenance). The two states are orthogonal.
 *
 * `destroyed_by_user_id` is nullable + nullOnDelete because admins/editors
 * who triggered the destruction may eventually be removed from the users
 * table — we keep the row but drop the FK reference, the timestamp and the
 * reason stay readable.
 *
 * Indexed on `destroyed_at` because the list view ternary filter
 * (All / Destroyed / Active) needs an indexed predicate to stay cheap once
 * the table has the eventual ~3-4k rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boxes', function (Blueprint $table): void {
            $table->timestamp('destroyed_at')->nullable()->after('deleted_at')
                ->comment('When the box was physically destroyed (after all docs catalogued).');
            $table->unsignedBigInteger('destroyed_by_user_id')->nullable()->after('destroyed_at');
            $table->text('destroyed_reason')->nullable()->after('destroyed_by_user_id');
            $table->foreign('destroyed_by_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->index('destroyed_at', 'boxes_destroyed_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('boxes', function (Blueprint $table): void {
            // Drop the FK before the column (cross-driver safe).
            $table->dropForeign(['destroyed_by_user_id']);
            $table->dropIndex('boxes_destroyed_at_idx');
            $table->dropColumn(['destroyed_at', 'destroyed_by_user_id', 'destroyed_reason']);
        });
    }
};
