<?php

declare(strict_types=1);

use App\Filament\Actions\Documents\MarkDisinfestedAction;
use App\Filament\Actions\Documents\SendToDisinfestationAction;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/**
 * F1 — a stale in-memory $doc->barcode_status must not clobber the box→document
 * barcode mirror. applyBarcodeStatus() writes the box (which bulk-updates the
 * documents via the mirror hook) and now syncs the in-memory $doc + original,
 * so the caller's subsequent $doc->save() persists the authoritative value.
 *
 * The actions are driven through their public ->action closure (the same path
 * the Filament UI uses), matching BoxAuthoritativeBarcodeTest.
 */
function mss_box(string $status): Box
{
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);

    return Box::factory()->create([
        'batch_id' => $batch->id,
        'box_type' => 'RAS',
        'barcode_status' => $status,
    ]);
}

function mss_docInBox(Box $box, array $overrides = []): Document
{
    /** @var Batch $batch */
    $batch = $box->batch;

    return Document::withoutGlobalScopes()->create(array_merge([
        'identifier' => 'DOC-MSS-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'Register',
        'series_id' => Series::factory()->create()->id,
        'repository_id' => $batch->repository_id,
        'batch_id' => $batch->id,
        'current_box_id' => $box->id,
        'barcode_status' => $box->barcode_status,
    ], $overrides));
}

it('keeps the in-memory document barcode_status consistent with the box after MarkDisinfested', function (): void {
    // admin bypasses the BelongsToRepository create-guard so the fixture
    // factories can stand up the batch/box/document in any repository.
    $this->actingAs(tap(User::factory()->create(), fn ($u) => $u->assignRole('admin')));

    $box = mss_box('OUT');
    $doc = mss_docInBox($box, ['barcode_status' => 'OUT', 'is_in_disinfestation' => true]);

    $closure = (fn () => $this->action)->call(MarkDisinfestedAction::make());
    $closure($doc, ['disinfestation_date' => now()->toDateString()]);

    // Box (authoritative) returns to IN; the persisted doc AND the in-memory
    // $doc must match it — not the stale pre-action 'OUT'.
    expect($box->fresh()->barcode_status)->toBe('IN');
    expect(Document::find($doc->id)->barcode_status)->toBe('IN');
    expect($doc->barcode_status)->toBe('IN');
});

it('keeps the in-memory document barcode_status consistent after SendToDisinfestation', function (): void {
    $this->actingAs(tap(User::factory()->create(), fn ($u) => $u->assignRole('admin')));

    $box = mss_box('IN');
    $doc = mss_docInBox($box, ['barcode_status' => 'IN', 'is_in_disinfestation' => false]);

    $closure = (fn () => $this->action)->call(SendToDisinfestationAction::make());
    $closure($doc);

    expect($box->fresh()->barcode_status)->toBe('OUT');
    expect(Document::find($doc->id)->barcode_status)->toBe('OUT');
    expect($doc->barcode_status)->toBe('OUT');
});
