<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxBarcodeHistory;
use App\Models\Document;
use App\Models\DocumentBarcodeHistory;
use App\Models\DocumentType;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/**
 * @return array{0: Repository, 1: Batch, 2: Box, 3: Series}
 */
function dbmm_ctx(string $boxStatus = 'IN'): array
{
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'box_type' => 'RAS',
        'barcode_status' => $boxStatus,
        'barcode' => 'BC-' . strtoupper(substr(uniqid(), -6)),
    ]);
    $series = Series::factory()->create();

    return [$repo, $batch, $box, $series];
}

it('re-mirrors barcode_status + batch_id when a document moves to another box (model save)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    [$repo, $batchIn, $boxIn, $series] = dbmm_ctx('IN');

    $batchOut = Batch::factory()->create(['repository_id' => $repo->id]);
    $boxOut = Box::factory()->create([
        'batch_id' => $batchOut->id,
        'box_type' => 'RAS',
        'barcode_status' => 'OUT',
        'barcode' => 'BC-OUT-1',
    ]);

    $doc = Document::factory()->create([
        'current_box_id' => $boxIn->id,
        'batch_id' => $batchIn->id,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'barcode_status' => 'IN',
    ]);

    // Move the document to the OUT box.
    $doc->current_box_id = $boxOut->id;
    $doc->save();

    $fresh = Document::find($doc->id);
    expect($fresh->barcode_status)->toBe('OUT');
    expect((int) $fresh->batch_id)->toBe((int) $boxOut->batch_id);
});

it('re-mirrors a document moved into a PERM_OUT box (reflects PERM_OUT + backfills the date), and also when the box goes PERM_OUT around it', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    [$repo, $batchIn, $boxIn, $series] = dbmm_ctx('IN');

    $batchPerm = Batch::factory()->create(['repository_id' => $repo->id]);
    $boxPerm = Box::factory()->create([
        'batch_id' => $batchPerm->id,
        'box_type' => 'RAS',
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => '2026-02-20',
        'barcode' => 'BC-PERM-1',
    ]);

    $doc = Document::factory()->create([
        'current_box_id' => $boxIn->id,
        'batch_id' => $batchIn->id,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'barcode_status' => 'IN',
        'disinfestation_date' => null,
    ]);

    // F1 (CRIT-2): a document legitimately RESIDES in a PERM_OUT box (it was
    // transferred out with the box). Moving it there is allowed and the
    // re-mirror makes the document reflect the box's PERM_OUT status and
    // backfills its disinfestation_date — so it can never read IN while sitting
    // in a PERM_OUT box, and the per-document A1.2 rule still holds.
    $doc->current_box_id = $boxPerm->id;
    $doc->save();

    $moved = Document::find($doc->id);
    expect($moved->barcode_status)->toBe('PERM_OUT')
        ->and($moved->disinfestation_date?->toDateString())->toBe('2026-02-20')
        ->and($moved->batch_id)->toBe($batchPerm->id);

    // The other path: the box a document is IN transitions to PERM_OUT — the
    // box mirror flips the doc to PERM_OUT and backfills the date.
    [$repo2, $batchIn2, $boxIn2, $series2] = dbmm_ctx('IN');
    $doc2 = Document::factory()->create([
        'current_box_id' => $boxIn2->id,
        'batch_id' => $batchIn2->id,
        'series_id' => $series2->id,
        'repository_id' => $repo2->id,
        'barcode_status' => 'IN',
        'disinfestation_date' => null,
    ]);
    $boxIn2->update(['barcode_status' => 'PERM_OUT', 'disinfestation_date' => '2026-02-20']);

    $after = Document::find($doc2->id);
    expect($after->barcode_status)->toBe('PERM_OUT')
        ->and($after->disinfestation_date?->toDateString())->toBe('2026-02-20');
});

it('clears the disinfestation_date when a document moves out of a PERM_OUT box into a non-PERM_OUT box', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    [$repo, $batchPerm, $boxPerm, $series] = dbmm_ctx('IN');
    // Reconfigure the first box as PERM_OUT with a date.
    $boxPerm->update(['barcode_status' => 'PERM_OUT', 'disinfestation_date' => '2026-02-20']);

    $batchIn = Batch::factory()->create(['repository_id' => $repo->id]);
    $boxIn = Box::factory()->create([
        'batch_id' => $batchIn->id,
        'box_type' => 'RAS',
        'barcode_status' => 'IN',
        'barcode' => 'BC-IN-MIRROR',
    ]);

    $doc = Document::factory()->create([
        'current_box_id' => $boxPerm->id,
        'batch_id' => $batchPerm->id,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => '2026-02-20',
    ]);

    // Move OUT of the PERM_OUT box into an IN box: the full re-mirror must drop
    // both the PERM_OUT status and the now-irrelevant disinfestation_date.
    $doc->current_box_id = $boxIn->id;
    $doc->save();

    $moved = Document::find($doc->id);
    expect($moved->barcode_status)->toBe('IN')
        ->and($moved->disinfestation_date)->toBeNull()
        ->and((int) $moved->batch_id)->toBe((int) $boxIn->batch_id);
});

it('realigns the disinfestation_date to the destination PERM_OUT box even when the document already had a different date', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    [$repo, $batchIn, $boxIn, $series] = dbmm_ctx('IN');

    $batchPerm = Batch::factory()->create(['repository_id' => $repo->id]);
    $boxPerm = Box::factory()->create([
        'batch_id' => $batchPerm->id,
        'box_type' => 'RAS',
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => '2026-03-15',
        'barcode' => 'BC-PERM-REALIGN',
    ]);

    $doc = Document::factory()->create([
        'current_box_id' => $boxIn->id,
        'batch_id' => $batchIn->id,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'barcode_status' => 'IN',
        'disinfestation_date' => '2025-01-01', // a stale, different date
    ]);

    $doc->current_box_id = $boxPerm->id;
    $doc->save();

    $moved = Document::find($doc->id);
    expect($moved->barcode_status)->toBe('PERM_OUT')
        ->and($moved->disinfestation_date?->toDateString())->toBe('2026-03-15');
});

it('does not create extra document barcode-history rows or recurse on a box move', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    [$repo, $batchIn, $boxIn, $series] = dbmm_ctx('IN');

    $batchOut = Batch::factory()->create(['repository_id' => $repo->id]);
    $boxOut = Box::factory()->create([
        'batch_id' => $batchOut->id,
        'box_type' => 'RAS',
        'barcode_status' => 'OUT',
        'barcode' => 'BC-OUT-2',
    ]);

    $doc = Document::factory()->create([
        'current_box_id' => $boxIn->id,
        'batch_id' => $batchIn->id,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'barcode_status' => 'IN',
        'barcode' => null,
    ]);

    $beforeDocHist = DocumentBarcodeHistory::where('document_id', $doc->id)->count();
    $beforeBoxHist = BoxBarcodeHistory::where('box_id', $boxOut->id)->count();

    $doc->current_box_id = $boxOut->id;
    $doc->save();

    // The custody re-sync uses saveQuietly() (no model events), so the
    // per-document barcode-VALUE history is untouched (barcode did not change)
    // and the destination box's own barcode history is not written either.
    expect(DocumentBarcodeHistory::where('document_id', $doc->id)->count())->toBe($beforeDocHist);
    expect(BoxBarcodeHistory::where('box_id', $boxOut->id)->count())->toBe($beforeBoxHist);
});

it('re-mirrors status when the document is moved via the Move-to-box flow (programmatic box change persists)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    [$repo, $batchIn, $boxIn, $series] = dbmm_ctx('IN');

    $batchOut = Batch::factory()->create(['repository_id' => $repo->id]);
    $boxOut = Box::factory()->create([
        'batch_id' => $batchOut->id,
        'box_type' => 'RAS',
        'barcode_status' => 'OUT',
        'barcode' => 'BC-OUT-3',
    ]);

    $doc = Document::factory()->create([
        'current_box_id' => $boxIn->id,
        'batch_id' => $batchIn->id,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'barcode_status' => 'IN',
    ]);

    // Simulate what MoveToBoxAction does to the model.
    $doc->current_box_id = $boxOut->id;
    $doc->batch_id = $boxOut->batch_id;
    $doc->save();

    $fresh = Document::find($doc->id);
    expect($fresh->barcode_status)->toBe('OUT');
});

it('locks current_box_id on the document edit form (disabled, not dehydrated)', function (): void {
    $repo = Repository::factory()->create();
    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    actingAs($user);

    $batchIn = Batch::factory()->create(['repository_id' => $repo->id]);
    $boxIn = Box::factory()->create([
        'batch_id' => $batchIn->id, 'box_type' => 'RAS',
        'barcode_status' => 'IN', 'barcode' => 'BC-LOCK-IN',
    ]);
    $series = Series::factory()->create();

    $batchOut = Batch::factory()->create(['repository_id' => $repo->id]);
    $boxOut = Box::factory()->create([
        'batch_id' => $batchOut->id,
        'box_type' => 'RAS',
        'barcode_status' => 'IN',
        'barcode' => 'BC-OUT-4',
    ]);

    // The edit form re-validates document_type against the active lookup.
    DocumentType::firstOrCreate(['name' => 'TEST'], ['is_active' => true]);

    $doc = Document::factory()->create([
        'current_box_id' => $boxIn->id,
        'batch_id' => $batchIn->id,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'identifier' => 'LOCK-1',
        'document_type' => 'TEST',
        'barcode_status' => 'IN',
    ]);

    // The current_box_id field is disabled on the edit form (box moves are
    // forced through the audited MoveToBoxAction). Assert the form renders it
    // disabled and that a save does not change the box.
    Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->assertFormFieldIsDisabled('current_box_id')
        ->call('save')
        ->assertHasNoFormErrors();

    expect((int) Document::find($doc->id)->current_box_id)->toBe((int) $boxIn->id);
});

it('locks batch_id on the document edit form (disabled, not dehydrated)', function (): void {
    $repo = Repository::factory()->create();
    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    actingAs($user);

    $batchIn = Batch::factory()->create(['repository_id' => $repo->id]);
    $boxIn = Box::factory()->create([
        'batch_id' => $batchIn->id, 'box_type' => 'RAS',
        'barcode_status' => 'IN', 'barcode' => 'BC-LOCKB-IN',
    ]);
    $series = Series::factory()->create();

    DocumentType::firstOrCreate(['name' => 'TEST'], ['is_active' => true]);

    $doc = Document::factory()->create([
        'current_box_id' => $boxIn->id,
        'batch_id' => $batchIn->id,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'identifier' => 'LOCKB-1',
        'document_type' => 'TEST',
        'barcode_status' => 'IN',
    ]);

    // batch_id is disabled on edit (it follows the box via MoveToBoxAction).
    // A save must not change the batch.
    Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->assertFormFieldIsDisabled('batch_id')
        ->call('save')
        ->assertHasNoFormErrors();

    expect((int) Document::find($doc->id)->batch_id)->toBe((int) $batchIn->id);
});
