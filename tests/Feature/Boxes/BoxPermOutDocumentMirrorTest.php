<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Regression (CodeRabbit, PR #186): once a box may be PERM_OUT without a
 * disinfestation date (RFQ #5 loosened, client feedback 2026-08-01), the box —
 * which is authoritative for barcode status — mirrors PERM_OUT onto its
 * documents. If the document CHECK (chk_documents_permout_requires_disinfestation)
 * were still in place, that mirror write would fail and the whole box save would
 * roll back. This was invisible on SQLite (no CHECK) and only broke on
 * MySQL/MariaDB — so this test also runs against the real driver in the CI's
 * dedicated MariaDB step.
 */
uses(RefreshDatabase::class);

it('a box with documents can go PERM_OUT without a disinfestation_date — the mirror does not fail', function () {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::factory()->create();
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    test()->actingAs($u);

    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'barcode_status' => 'IN', 'disinfestation_date' => null]);
    $doc = Document::withoutGlobalScopes()->create([
        'identifier' => 'MIRROR-1', 'document_type' => 'Register',
        'series_id' => Series::factory()->create()->id, 'repository_id' => $repo->id,
        'batch_id' => $batch->id, 'current_box_id' => $box->id,
        'barcode_status' => 'IN', 'disinfestation_date' => null,
    ]);

    // The box is authoritative and mirrors PERM_OUT onto its document.
    $box->update(['barcode_status' => 'PERM_OUT']);

    expect($box->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($box->fresh()->disinfestation_date)->toBeNull()
        ->and($doc->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($doc->fresh()->disinfestation_date)->toBeNull();
});

it('the documents PERM_OUT disinfestation CHECK is gone on MySQL/MariaDB', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $series = Series::factory()->create();
    // A raw PERM_OUT insert with no disinfestation_date must NOT be rejected by
    // a DB-level CHECK. We bypass the model guard on purpose (raw insert) so the
    // only thing that could reject this row is the dropped CHECK constraint.
    DB::table('documents')->insert([
        'identifier' => 'RAW-PERMOUT-1', 'document_type' => 'Register',
        'series_id' => $series->id, 'repository_id' => $repo->id, 'batch_id' => $batch->id,
        'barcode_status' => 'PERM_OUT', 'disinfestation_date' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('documents')->where('identifier', 'RAW-PERMOUT-1')->exists())->toBeTrue();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'CHECK constraint only exists on MySQL/MariaDB');
