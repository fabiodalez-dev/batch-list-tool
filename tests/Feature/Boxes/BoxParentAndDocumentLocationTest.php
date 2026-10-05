<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client, 2026-10-02.
 *
 * 1. Rebuilding barcode histories, the box sheet carried each box's PREVIOUS
 *    barcode in "Parent box number". It resolved to the box itself, and five
 *    RAS boxes of batch 19 became their own parent; the list showed "6,088".
 * 2. "If all the documents of a box have the same location, can we imply that
 *    the box is in that location?"
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
    $this->series = Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true]);
});

function bpl_box(int $batchId, string $number, ?string $barcode, array $extra = []): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(array_merge([
        'box_type' => 'RAS', 'box_number' => $number, 'batch_id' => $batchId, 'barcode' => $barcode,
        'barcode_status' => 'PERM_OUT', 'is_legacy' => true,
    ], $extra));
}

/**
 * One box-sheet row through the real importer, with the client's own headers.
 *
 * @param array<string, string> $row
 */
function bpl_importBox(array $row, int $userId): void
{
    $map = ImportWizard::guessColumnMap(BoxImporter::class, array_keys($row));
    // "Barcode RAS 3" is not guessed: the client maps it onto Barcode by hand
    // on the wizard's mapping step, which is what this does.
    if (array_key_exists('Barcode RAS 3', $row)) {
        $map['barcode'] = 'Barcode RAS 3';
    }
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'box.xlsx', 'file_path' => '/tmp/box.xlsx',
        'importer' => BoxImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new BoxImporter($import, $map, []))($row);
}

/**
 * @param array<string, string> $over
 * @return array<string, string>
 */
function bpl_boxRow(array $over): array
{
    return array_merge([
        'Box type' => 'RAS', 'Box number' => '4', 'Batch number' => '19', 'Parent box number' => '',
        'Barcode RAS 3' => '', 'Barcode status' => 'Perm Out', 'Is legacy' => 'Yes',
    ], $over);
}

it('refuses a row whose Parent box number is the box itself, and says why', function (): void {
    $box = bpl_box($this->batch->id, '4', 'AB11999');

    // The client's row: the box's previous barcode in Parent box number.
    $error = null;

    try {
        bpl_importBox(bpl_boxRow(['Parent box number' => 'AB11999', 'Barcode RAS 3' => 'AA18701']), $this->user->id);
    } catch (ValidationException|RowImportFailedException $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull()
        ->and(implode(' ', $error instanceof ValidationException ? $error->validator->errors()->all() : [$error->getMessage()]))
        ->toContain('is this box itself')
        ->toContain('barcode history');

    $box->refresh();
    expect($box->parent_box_id)->toBeNull()
        ->and($box->barcode)->toBe('AB11999');
});

it('still records the barcode history when Parent box number is left empty', function (): void {
    $box = bpl_box($this->batch->id, '4', 'AB11999');

    bpl_importBox(bpl_boxRow(['Barcode RAS 3' => 'AA18701']), $this->user->id);

    $box->refresh();
    expect($box->barcode)->toBe('AA18701')
        ->and($box->parent_box_id)->toBeNull()
        ->and($box->barcodeHistory()->pluck('previous_barcode', 'new_barcode')->all())->toBe(['AA18701' => 'AB11999']);
});

it('still links an In Situ box to the RAS box it sits in', function (): void {
    $ras = bpl_box($this->batch->id, '7', 'AA00007');

    bpl_importBox(['Box type' => 'IN_SITU', 'Box number' => '7A', 'Batch number' => '19', 'Parent box number' => 'AA00007', 'Barcode status' => 'IN', 'Is legacy' => 'Yes'], $this->user->id);

    expect(Box::withoutGlobalScopes()->where('box_number', '7A')->value('parent_box_id'))->toBe($ras->id);
});

it('never saves a box as its own parent, whatever the path', function (): void {
    $box = bpl_box($this->batch->id, '4', 'AB11999');

    $box->parent_box_id = $box->id;

    expect(fn () => $box->save())->toThrow(DomainException::class, 'own parent');
});

it('shows the parent in the box list by type, number and barcode, not its id', function (): void {
    $ras = bpl_box($this->batch->id, '7', 'AA00007');
    $child = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'IN_SITU', 'box_number' => '7A', 'batch_id' => $this->batch->id, 'parent_box_id' => $ras->id, 'barcode_status' => 'IN', 'is_legacy' => true]);

    Livewire::test(ListBoxes::class)
        ->assertTableColumnFormattedStateSet('parent_box_id', 'RAS 7 (batch 19) · AA00007', $child)
        ->assertDontSee('Parent Box Id');
});

it('gives a box with no location the one location all its documents share', function (): void {
    $box = bpl_box($this->batch->id, '30', 'AA00030');
    $shelf = Location::factory()->create(['code' => 'NRA-1-45', 'name' => 'Shelf 45', 'repository_id' => $this->repo->id]);
    $doc = fn (string $id, ?int $loc) => Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => $id, 'repository_id' => $this->repo->id, 'series_id' => $this->series->id,
        'document_type' => 'T', 'current_box_id' => $box->id, 'location_id' => $loc,
    ]);

    $doc('L-1', $shelf->id);
    $doc('L-2', null); // no location of its own: it inherits the box's, it does not disagree
    $doc('L-3', $shelf->id);

    $box->refresh();
    $row = $box->locationHistory()->latest('id')->first();
    expect($box->location_id)->toBe($shelf->id)
        ->and($row->source)->toBe(Box::LOCATION_SOURCE_DOCUMENTS)
        ->and($row->notes)->toContain('Taken from its documents');
});

it('takes the location back off when the documents stop agreeing, and follows them when they agree again', function (): void {
    $box = bpl_box($this->batch->id, '31', 'AA00031');
    $a = Location::factory()->create(['code' => 'NRA-A', 'name' => 'A', 'repository_id' => $this->repo->id]);
    $b = Location::factory()->create(['code' => 'NRA-B', 'name' => 'B', 'repository_id' => $this->repo->id]);
    $one = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'D-1', 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => 'T', 'current_box_id' => $box->id, 'location_id' => $a->id]);
    expect($box->refresh()->location_id)->toBe($a->id);

    $two = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'D-2', 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => 'T', 'current_box_id' => $box->id, 'location_id' => $b->id]);
    expect($box->refresh()->location_id)->toBeNull();

    $one->update(['location_id' => $b->id]);
    expect($box->refresh()->location_id)->toBe($b->id);

    // A document leaving the box re-checks the box it left.
    $two->update(['location_id' => $a->id]);
    expect($box->refresh()->location_id)->toBeNull();
    $two->update(['current_box_id' => null]);
    expect($box->refresh()->location_id)->toBe($b->id);
});

it('never touches a location somebody set on the box', function (): void {
    $manual = Location::factory()->create(['code' => 'NRA-M', 'name' => 'Manual', 'repository_id' => $this->repo->id]);
    $other = Location::factory()->create(['code' => 'NRA-O', 'name' => 'Other', 'repository_id' => $this->repo->id]);
    $box = bpl_box($this->batch->id, '32', 'AA00032', ['location_id' => $manual->id]);

    Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'M-1', 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => 'T', 'current_box_id' => $box->id, 'location_id' => $other->id]);

    expect($box->refresh()->location_id)->toBe($manual->id);
});

it('does it through the documents import, the path the client uses', function (): void {
    $box = bpl_box($this->batch->id, '40', 'AA00040');
    $shelf = Location::factory()->create(['code' => 'NRA-2-10', 'name' => 'Shelf 10', 'repository_id' => $this->repo->id]);

    foreach (['IMP-1', 'IMP-2'] as $identifier) {
        $row = ['Document Identifier' => $identifier, 'Series' => 'REG', 'RAS Batch 1' => '19', 'RAS Box 1' => '40', 'NRA Location' => 'NRA-2-10'];
        $map = ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($row));
        EntityResolver::flushMemo();
        /** @var Import $import */
        $import = Import::query()->create([
            'completed_at' => null, 'file_name' => 'docs.xlsx', 'file_path' => '/tmp/docs.xlsx',
            'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
            'successful_rows' => 0, 'user_id' => $this->user->id,
        ]);
        (new DocumentImporter($import, $map, []))($row);
    }

    expect(Document::withoutGlobalScopes()->whereIn('identifier', ['IMP-1', 'IMP-2'])->pluck('current_box_id')->unique()->all())->toBe([$box->id])
        ->and($box->refresh()->location_id)->toBe($shelf->id);
});

it('keeps the destruction date when the same box is imported again with Destroyed = Yes', function (): void {
    $box = bpl_box($this->batch->id, '50', 'AA00050');

    Carbon::setTestNow('2026-10-02 05:58:01');
    bpl_importBox(bpl_boxRow(['Box number' => '50', 'Barcode RAS 3' => 'AB00050', 'Destroyed' => 'Yes']), $this->user->id);
    expect($box->refresh()->destroyed_at?->toDateTimeString())->toBe('2026-10-02 05:58:01');

    // The next barcode generation, five minutes later: same Yes, same date.
    Carbon::setTestNow('2026-10-02 06:03:02');
    bpl_importBox(bpl_boxRow(['Box number' => '50', 'Barcode RAS 3' => 'AC00050', 'Destroyed' => 'Yes']), $this->user->id);
    expect($box->refresh()->destroyed_at?->toDateTimeString())->toBe('2026-10-02 05:58:01')
        ->and($box->barcode)->toBe('AC00050');

    // A date in the cell is information: it is taken.
    bpl_importBox(bpl_boxRow(['Box number' => '50', 'Barcode RAS 3' => 'AC00050', 'Destroyed' => '15/06/2024']), $this->user->id);
    expect($box->refresh()->destroyed_at?->toDateString())->toBe('2024-06-15');

    Carbon::setTestNow();
});
