<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\BoxResource;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client, 2026-10-05: "I deleted all boxes to try to import once again ... I
 * think when I delete the boxes I am not completely wiping out the history."
 *
 * Delete only archives a box, and an import of the same box (type, number,
 * batch) brings the archived one back — with its history. The box list now
 * shows archived boxes, restores them, and deletes them for good.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    bl_seedShieldPermissions();
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    $this->batch = Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(['batch_number' => '19', 'repository_id' => $this->repo->id]);
});

function arb_box(int $batchId, string $number, string $barcode): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS', 'box_number' => $number, 'batch_id' => $batchId, 'barcode' => $barcode,
        'barcode_status' => 'PERM_OUT', 'is_legacy' => true,
    ]);
}

function arb_import(string $number, string $barcode, int $userId): void
{
    $row = ['Box type' => 'RAS', 'Box number' => $number, 'Batch number' => '19', 'Barcode' => $barcode, 'Barcode status' => 'Perm Out', 'Is legacy' => 'Yes'];
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'box.xlsx', 'file_path' => '/tmp/box.xlsx',
        'importer' => BoxImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new BoxImporter($import, ImportWizard::guessColumnMap(BoxImporter::class, array_keys($row)), []))($row);
}

it('hides deleted boxes from the list, and shows them under the Archived filter', function (): void {
    $kept = arb_box($this->batch->id, '1', 'AA00001');
    $deleted = arb_box($this->batch->id, '2', 'AA00002');
    $deleted->delete();

    Livewire::test(ListBoxes::class)
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$deleted])
        ->filterTable(TrashedFilter::class, false) // only archived
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$kept]);
});

it('restores archived boxes', function (): void {
    $box = arb_box($this->batch->id, '3', 'AA00003');
    $box->delete();

    Livewire::test(ListBoxes::class)
        ->filterTable(TrashedFilter::class, false)
        ->callTableBulkAction('restore', [$box]);

    expect(Box::withoutGlobalScopes()->find($box->id)?->trashed())->toBeFalse();
});

it('opens an archived box, so it can be restored from its page', function (): void {
    $box = arb_box($this->batch->id, '4', 'AA00004');
    $box->delete();

    $this->get(BoxResource::getUrl('view', ['record' => $box]))->assertOk();
});

it('deletes boxes for good with their history, so a new import starts clean — the client\'s case', function (): void {
    $box = arb_box($this->batch->id, '5', 'AB11999');
    $box->update(['barcode' => 'AA18701']);
    expect(DB::table('box_barcode_history')->where('box_id', $box->id)->count())->toBe(1);

    // What she did: delete. The import brings the same box back, history and all.
    $box->delete();
    arb_import('5', 'AB11999', $this->user->id);
    expect(Box::withoutGlobalScopes()->where('box_number', '5')->value('id'))->toBe($box->id)
        ->and(DB::table('box_barcode_history')->where('box_id', $box->id)->count())->toBe(2);

    // Delete, then Delete permanently from the Archived filter.
    $box->refresh()->delete();
    Livewire::test(ListBoxes::class)
        ->filterTable(TrashedFilter::class, false)
        ->callTableBulkAction('forceDelete', [$box]);

    expect(Box::withoutGlobalScopes()->find($box->id))->toBeNull()
        ->and(DB::table('box_barcode_history')->where('box_id', $box->id)->count())->toBe(0);

    arb_import('5', 'AB11999', $this->user->id);
    $fresh = Box::withoutGlobalScopes()->where('box_number', '5')->firstOrFail();
    expect($fresh->id)->not->toBe($box->id)
        ->and($fresh->barcodeHistory()->count())->toBe(0);
});

it('keeps a box that still contains documents, and says so', function (): void {
    $box = arb_box($this->batch->id, '6', 'AA00006');
    $empty = arb_box($this->batch->id, '7', 'AA00007');
    $series = Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'ARB-1', 'repository_id' => $this->repo->id, 'series_id' => $series->id, 'document_type' => 'T', 'current_box_id' => $box->id]);
    $doc->delete(); // an archived document still counts: restoring it must find its box
    $box->delete();
    $empty->delete();

    Livewire::test(ListBoxes::class)
        ->filterTable(TrashedFilter::class, false)
        ->callTableBulkAction('forceDelete', [$box, $empty])
        ->assertNotified();

    expect(Box::withoutGlobalScopes()->find($box->id))->not->toBeNull()
        ->and(Box::withoutGlobalScopes()->find($empty->id))->toBeNull()
        ->and(Document::withTrashed()->withoutGlobalScopes()->find($doc->id)->current_box_id)->toBe($box->id);
});
