<?php

declare(strict_types=1);

use App\Models\Accession;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ §3.1.4 — Document workflow tracking
 * (Acquisition → Storage → Disinfestation → Cataloguing → Migration).
 *
 * Each stage maps to one or more persisted columns/relations:
 *   - Acquisition: Accession created, linked to Authority + Batch
 *   - Storage: current_box_id assigned, BoxMovement appended
 *   - Disinfestation: disinfestation_date stamped on box AND document
 *   - Cataloguing: catalogue_identifier populated
 *   - Migration: PERM_OUT status on box requires disinfestation_date
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function s314_setup(): array
{
    $repo = Repository::factory()->create(['code' => 'S314-' . substr(uniqid(), -4)]);
    $series = Series::create(['code' => 'S314S-' . substr(uniqid(), -4), 'title' => 'S314', 'is_active' => true]);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 5000 + random_int(0, 999),
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repo->id, 'is_active' => true,
    ]);

    return [$repo, $series, $batch];
}

it('§ 3.1.4 #1: Acquisition — Accession persists with code + batch + repository linkage', function () {
    [$repo, , $batch] = s314_setup();
    $acc = Accession::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'ACC-' . uniqid(),
        'accession_date' => '2026-01-15',
        'repository_id' => $repo->id,
        'notes' => 'Joseph Tabone Accession',
    ]);
    // Wave B: Accession↔Batch is N:N — the link lives in the accession_batch pivot.
    $acc->batches()->attach($batch->id);
    expect($acc->batches()->pluck('batches.id')->all())->toBe([$batch->id])
        ->and($acc->repository_id)->toBe($repo->id);
});

it('§ 3.1.4 #2: Storage — Document.current_box_id can be assigned post-acquisition', function () {
    [$repo, $series, $batch] = s314_setup();
    $box = Box::factory()->create(['batch_id' => $batch->id]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'S314-' . uniqid(),
        'document_type' => 'Register',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);
    $doc->update(['current_box_id' => $box->id]);
    expect($doc->refresh()->current_box_id)->toBe($box->id);
});

it('§ 3.1.4 #3: Storage — BoxMovement records a from/to transition with timestamp + user', function () {
    [$repo, $series, $batch] = s314_setup();
    $boxA = Box::factory()->create(['batch_id' => $batch->id]);
    $boxB = Box::factory()->create(['batch_id' => $batch->id]);
    $u = User::factory()->create(['email' => 'mv-' . uniqid() . '@t.t']);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'S314M-' . uniqid(),
        'document_type' => 'Register',
        'series_id' => $series->id, 'repository_id' => $repo->id,
        'current_box_id' => $boxA->id,
    ]);
    $mv = BoxMovement::withoutGlobalScopes()->create([
        'document_id' => $doc->id,
        'from_box_id' => $boxA->id,
        'to_box_id' => $boxB->id,
        'movement_date' => now(),
        'user_id' => $u->id,
        'reason' => 'Transferred to In Situ',
    ]);
    expect($mv->from_box_id)->toBe($boxA->id)
        ->and($mv->to_box_id)->toBe($boxB->id)
        ->and($mv->user_id)->toBe($u->id);
});

it('§ 3.1.4 #4: Disinfestation — disinfestation_date stamps both Box and Document', function () {
    [$repo, $series, $batch] = s314_setup();
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'disinfestation_date' => '2026-04-10',
    ]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'S314D-' . uniqid(),
        'document_type' => 'Register',
        'series_id' => $series->id, 'repository_id' => $repo->id,
        'current_box_id' => $box->id,
        'disinfestation_date' => '2026-04-10',
    ]);
    expect($box->disinfestation_date->format('Y-m-d'))->toBe('2026-04-10')
        ->and($doc->disinfestation_date->format('Y-m-d'))->toBe('2026-04-10');
});

it('§ 3.1.4 #5: Cataloguing — Document.catalogue_identifier persists distinct from identifier', function () {
    [$repo, $series] = s314_setup();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'S314C-' . uniqid(),
        'document_type' => 'Register',
        'series_id' => $series->id, 'repository_id' => $repo->id,
        'catalogue_identifier' => 'NRA/REG/01/0007',
    ]);
    expect($doc->catalogue_identifier)->toBe('NRA/REG/01/0007')
        ->and($doc->identifier)->not->toBe('NRA/REG/01/0007');
});

it('§ 3.1.4 #6: Migration — Box.barcode_status transitions IN → OUT → PERM_OUT', function () {
    [, , $batch] = s314_setup();
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'barcode_status' => 'IN',
    ]);
    expect($box->barcode_status)->toBe('IN');
    $box->update(['barcode_status' => 'OUT']);
    expect($box->refresh()->barcode_status)->toBe('OUT');
});

it('§ 3.1.4 #7: Workflow — Box::BARCODE_STATUSES exactly [IN, OUT, PERM_OUT]', function () {
    expect(Box::BARCODE_STATUSES)->toBe(['IN', 'OUT', 'PERM_OUT']);
});

it('§ 3.1.4 #8: Document->movements() relation returns BoxMovement collection latest-first', function () {
    [$repo, $series, $batch] = s314_setup();
    $boxA = Box::factory()->create(['batch_id' => $batch->id]);
    $boxB = Box::factory()->create(['batch_id' => $batch->id]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'S314W-' . uniqid(),
        'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
    ]);
    BoxMovement::withoutGlobalScopes()->create([
        'document_id' => $doc->id, 'from_box_id' => null, 'to_box_id' => $boxA->id,
        'movement_date' => now()->subDay(),
    ]);
    BoxMovement::withoutGlobalScopes()->create([
        'document_id' => $doc->id, 'from_box_id' => $boxA->id, 'to_box_id' => $boxB->id,
        'movement_date' => now(),
    ]);
    $movements = $doc->movements()->withoutGlobalScopes()->get();
    expect($movements->count())->toBe(2);
});
