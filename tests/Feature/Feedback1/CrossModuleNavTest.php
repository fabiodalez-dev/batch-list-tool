<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource;
use App\Filament\Resources\BoxResource\Pages\CreateBox;
use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 Wave B — cross-module navigation + batch-scoped box numbers + the
 * "add document from a box" prefill.
 *
 *  - B3: Batch → Boxes filtered by that batch (the BoxResource `batch` filter).
 *  - B4: Box → Documents filtered by that box (Documents `current_box_id`).
 *  - B5: box_number unique within a batch.
 *  - B6: CreateDocument pre-fills current_box_id from the query string.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function cmn_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'cmn-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => null,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function cmn_repo(): Repository
{
    return Repository::factory()->create(['code' => 'CMN_' . strtoupper(substr(uniqid(), -6))]);
}

function cmn_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'CMNS_' . substr(uniqid(), -4)],
        ['title' => 'CMN series', 'is_active' => true],
    );
}

function cmn_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'CMN-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

/* ============================ B3 — Batch → Boxes =========================== */

it('the BoxResource index URL carries the batch filter param shape used by BatchResource navigation', function (): void {
    $url = BoxResource::getUrl('index', [
        'filters' => ['batch' => ['values' => [42]]],
    ]);

    // Filament binds $tableFilters as #[Url(as: 'filters')], so the query-string
    // key MUST be `filters` — NOT `tableFilters` — or the link lands unfiltered.
    expect(urldecode($url))->toContain('filters')
        ->and(urldecode($url))->not->toContain('tableFilters')
        ->and(urldecode($url))->toContain('batch')
        ->and(urldecode($url))->toContain('42');
});

it('Boxes list given the batch filter param shows only that batch boxes (B3)', function (): void {
    $this->actingAs(cmn_actAsSuperAdmin());

    $repo = cmn_repo();
    $batchA = Batch::factory()->create(['batch_number' => 301, 'repository_id' => $repo->id]);
    $batchB = Batch::factory()->create(['batch_number' => 302, 'repository_id' => $repo->id]);
    $boxA = Box::factory()->create(['batch_id' => $batchA->id, 'box_number' => 'A1']);
    $boxB = Box::factory()->create(['batch_id' => $batchB->id, 'box_number' => 'B1']);

    Livewire::test(ListBoxes::class)
        ->filterTable('batch', [$batchA->id])
        ->assertCanSeeTableRecords([$boxA])
        ->assertCanNotSeeTableRecords([$boxB]);
});

it('arriving at the Boxes list via the navigation filter query-string applies the batch filter (B3 round-trip)', function (): void {
    $this->actingAs(cmn_actAsSuperAdmin());

    $repo = cmn_repo();
    $batchA = Batch::factory()->create(['batch_number' => 401, 'repository_id' => $repo->id]);
    $batchB = Batch::factory()->create(['batch_number' => 402, 'repository_id' => $repo->id]);
    $boxA = Box::factory()->create(['batch_id' => $batchA->id, 'box_number' => 'R1']);
    $boxB = Box::factory()->create(['batch_id' => $batchB->id, 'box_number' => 'R2']);

    // Drive the EXACT query-string key Filament reads ('filters'), as produced
    // by BatchResource::getUrl(... ['filters' => ...]) — proves the round trip.
    Livewire::withQueryParams(['filters' => ['batch' => ['values' => [(string) $batchA->id]]]])
        ->test(ListBoxes::class)
        ->assertCanSeeTableRecords([$boxA])
        ->assertCanNotSeeTableRecords([$boxB]);
});

/* ============================ B4 — Box → Documents ========================= */

it('the DocumentResource index URL carries the current_box_id filter param shape (B4)', function (): void {
    $url = DocumentResource::getUrl('index', [
        'filters' => ['current_box_id' => ['values' => [7]]],
    ]);

    expect(urldecode($url))->toContain('filters')
        ->and(urldecode($url))->not->toContain('tableFilters')
        ->and(urldecode($url))->toContain('current_box_id')
        ->and(urldecode($url))->toContain('7');
});

it('Documents list given the box filter param shows only that box documents (B4)', function (): void {
    $this->actingAs(cmn_actAsSuperAdmin());

    $repo = cmn_repo();
    $series = cmn_series();
    $batch = Batch::factory()->create(['batch_number' => 310, 'repository_id' => $repo->id]);
    $boxA = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => 'X1']);
    $boxB = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => 'X2']);
    $docA = cmn_doc($repo->id, $series->id, ['current_box_id' => $boxA->id]);
    $docB = cmn_doc($repo->id, $series->id, ['current_box_id' => $boxB->id]);

    Livewire::test(ListDocuments::class)
        ->filterTable('current_box_id', [$boxA->id])
        ->assertCanSeeTableRecords([$docA])
        ->assertCanNotSeeTableRecords([$docB]);
});

it('arriving at the Documents list via the navigation filter query-string applies the box filter (B4 round-trip)', function (): void {
    $this->actingAs(cmn_actAsSuperAdmin());

    $repo = cmn_repo();
    $series = cmn_series();
    $batch = Batch::factory()->create(['batch_number' => 411, 'repository_id' => $repo->id]);
    $boxA = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => 'Y1']);
    $boxB = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => 'Y2']);
    $docA = cmn_doc($repo->id, $series->id, ['current_box_id' => $boxA->id]);
    $docB = cmn_doc($repo->id, $series->id, ['current_box_id' => $boxB->id]);

    Livewire::withQueryParams(['filters' => ['current_box_id' => ['values' => [(string) $boxA->id]]]])
        ->test(ListDocuments::class)
        ->assertCanSeeTableRecords([$docA])
        ->assertCanNotSeeTableRecords([$docB]);
});

/* ============================ B5 — box_number unique within batch ========== */

it('rejects a box_number already used in the same batch (B5)', function (): void {
    $this->actingAs(cmn_actAsSuperAdmin());

    $repo = cmn_repo();
    $batch = Batch::factory()->create(['batch_number' => 320, 'repository_id' => $repo->id]);
    Box::factory()->create(['batch_id' => $batch->id, 'box_number' => '5']);

    Livewire::test(CreateBox::class)
        ->fillForm([
            'box_type' => 'RAS',
            'batch_id' => $batch->id,
            'box_number' => '5',
            'is_legacy' => false,
            'barcode_status' => 'IN',
        ])
        ->call('create')
        ->assertHasFormErrors(['box_number']);
});

it('allows the same box_number in a different batch (B5)', function (): void {
    $this->actingAs(cmn_actAsSuperAdmin());

    $repo = cmn_repo();
    $batchA = Batch::factory()->create(['batch_number' => 330, 'repository_id' => $repo->id]);
    $batchB = Batch::factory()->create(['batch_number' => 331, 'repository_id' => $repo->id]);
    Box::factory()->create(['batch_id' => $batchA->id, 'box_number' => '7']);

    Livewire::test(CreateBox::class)
        ->fillForm([
            'box_type' => 'RAS',
            'batch_id' => $batchB->id,
            'box_number' => '7',
            // Feedback1 Wave C2.1 — a RAS box now requires a barcode.
            'barcode' => 'BC-CMN-7',
            'is_legacy' => false,
            'barcode_status' => 'IN',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Box::query()->where('batch_id', $batchB->id)->where('box_number', '7')->exists())->toBeTrue();
});

it('lets a box keep its own box_number on edit (B5)', function (): void {
    $this->actingAs(cmn_actAsSuperAdmin());

    $repo = cmn_repo();
    $batch = Batch::factory()->create(['batch_number' => 340, 'repository_id' => $repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => '9']);

    Livewire::test(EditBox::class, ['record' => $box->getKey()])
        ->fillForm(['box_number' => '9'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('exposes used box numbers as helper text for the selected batch (B5)', function (): void {
    $repo = cmn_repo();
    $batch = Batch::factory()->create(['batch_number' => 350, 'repository_id' => $repo->id]);
    Box::factory()->create(['batch_id' => $batch->id, 'box_number' => '1']);
    Box::factory()->create(['batch_id' => $batch->id, 'box_number' => '2']);

    $helper = BoxResource::usedBoxNumbersHelper($batch->id);

    expect($helper)->toContain('1')
        ->and($helper)->toContain('2')
        ->and(BoxResource::usedBoxNumbersHelper(null))->toContain('Pick a batch');
});

/* ============================ B6 — Add document from box =================== */

it('CreateDocument pre-fills current_box_id from the query param (B6)', function (): void {
    $this->actingAs(cmn_actAsSuperAdmin());

    $repo = cmn_repo();
    $batch = Batch::factory()->create(['batch_number' => 360, 'repository_id' => $repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => 'Z9']);

    Livewire::withQueryParams(['current_box_id' => $box->getKey()])
        ->test(CreateDocument::class)
        ->assertOk()
        ->assertFormSet(['current_box_id' => $box->getKey()]);
});

it('the add-document row action URL targets the Document create form with current_box_id (B6)', function (): void {
    $repo = cmn_repo();
    $batch = Batch::factory()->create(['batch_number' => 370, 'repository_id' => $repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => 'Z10']);

    $url = DocumentResource::getUrl('create', ['current_box_id' => $box->getKey()]);

    expect(urldecode($url))->toContain('current_box_id')
        ->and(urldecode($url))->toContain((string) $box->getKey());
});
