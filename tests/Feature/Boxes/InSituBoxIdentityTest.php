<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client, 2026-10-05, on In Situ / NRA / MAV boxes:
 *   "If only one document for NRA512 was in Batch 3 Box 121, how should the
 *    import template be? My biggest question is about the parent box number."
 *   "What if a box, say NRA512, is a combination of documents coming from
 *    different RAS boxes? MAV1 history is Batch 28 Box 110, Batch 28 Box 143
 *    and a document we can't trace."
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
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true]);
    foreach (['3', '4', '19', '28'] as $n) {
        $this->{'b' . $n} = Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(['batch_number' => $n, 'repository_id' => $this->repo->id]);
    }
});

function isb_ras(int $batchId, string $number, ?string $barcode = null): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS', 'box_number' => $number, 'batch_id' => $batchId, 'barcode' => $barcode,
        'barcode_status' => 'PERM_OUT', 'is_legacy' => true,
    ]);
}

/**
 * @param array<string, string> $row
 */
function isb_run(string $importer, array $row, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'f.xlsx', 'file_path' => '/tmp/f.xlsx',
        'importer' => $importer, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new $importer($import, ImportWizard::guessColumnMap($importer, array_keys($row)), []))($row);
}

/**
 * @param array<string, string> $over
 */
function isb_boxRow(array $over): array
{
    return array_merge(['Box type' => 'NRA', 'Box number' => '512', 'Batch number' => '', 'Parent box number' => '', 'Barcode status' => '', 'Is legacy' => 'Yes'], $over);
}

function isb_docRow(string $identifier, string $rasBatch, string $rasBox, string $inSitu): array
{
    return ['Document Identifier' => $identifier, 'Series' => 'REG', 'RAS Batch 1' => $rasBatch, 'RAS Box 1' => $rasBox, 'In Situ Box 1' => $inSitu];
}

function isb_boxes(string $type, string $number)
{
    return Box::withoutGlobalScopes()->whereNull('deleted_at')->where('box_type', $type)->where('box_number', $number)->get();
}

it('never turns a RAS box into an NRA box that has the same number in the same batch', function (): void {
    $ras = isb_ras($this->b19->id, '4', 'AA00004');

    isb_run(BoxImporter::class, isb_boxRow(['Box number' => '4', 'Batch number' => '19', 'Parent box number' => 'AA00004']), $this->user->id);

    expect($ras->refresh()->box_type)->toBe('RAS')
        ->and(isb_boxes('NRA', '4'))->toHaveCount(1)
        ->and(isb_boxes('NRA', '4')->first()->parent_box_id)->toBe($ras->id);
});

it('finds the NRA box of the box sheet from the documents sheet, even when the box row named a batch', function (): void {
    $ras = isb_ras($this->b3->id, '121', 'AA00121');
    isb_run(BoxImporter::class, isb_boxRow(['Batch number' => '3', 'Parent box number' => 'AA00121']), $this->user->id);

    isb_run(DocumentImporter::class, isb_docRow('NRA-DOC-1', '3', '121', 'NRA512'), $this->user->id);

    expect(isb_boxes('NRA', '512'))->toHaveCount(1)
        ->and(isb_boxes('NRA', '512')->first()->parent_box_id)->toBe($ras->id);
});

it('updates the NRA box the documents sheet created, when the box sheet comes after it with a batch', function (): void {
    isb_ras($this->b3->id, '121', 'AA00121');
    isb_run(DocumentImporter::class, isb_docRow('NRA-DOC-2', '3', '121', 'NRA512'), $this->user->id);
    expect(isb_boxes('NRA', '512'))->toHaveCount(1);

    isb_run(BoxImporter::class, isb_boxRow(['Batch number' => '3', 'Parent box number' => 'AA00121', 'Notes' => 'from the box sheet']), $this->user->id);

    expect(isb_boxes('NRA', '512'))->toHaveCount(1)
        ->and(isb_boxes('NRA', '512')->first()->notes)->toBe('from the box sheet');
});

it('takes the parent as batch/box — "3/121" — when the box number alone is ambiguous', function (): void {
    $right = isb_ras($this->b3->id, '121');
    isb_ras($this->b4->id, '121');

    isb_run(BoxImporter::class, isb_boxRow(['Parent box number' => '3/121']), $this->user->id);

    expect(isb_boxes('NRA', '512')->first()->parent_box_id)->toBe($right->id);
});

it('explains the batch/box form when the number alone is ambiguous', function (): void {
    isb_ras($this->b3->id, '121');
    isb_ras($this->b4->id, '121');

    $message = '';

    try {
        isb_run(BoxImporter::class, isb_boxRow(['Parent box number' => '121']), $this->user->id);
    } catch (ValidationException $e) {
        $message = implode(' ', $e->validator->errors()->all());
    }

    expect($message)->toContain('ambiguous')->toContain('/121');
});

it('looks for the parent number in the row\'s own batch first', function (): void {
    $right = isb_ras($this->b3->id, '121');
    isb_ras($this->b4->id, '121');

    isb_run(BoxImporter::class, isb_boxRow(['Box type' => 'IN_SITU', 'Box number' => '9', 'Batch number' => '3', 'Parent box number' => '121']), $this->user->id);

    expect(isb_boxes('IN_SITU', '9')->first()->parent_box_id)->toBe($right->id);
});

it('records every RAS box an In Situ box was assembled from — the MAV1 case', function (): void {
    $b110 = isb_ras($this->b28->id, '110', 'AA00110');
    $b143 = isb_ras($this->b28->id, '143', 'AA00143');

    isb_run(DocumentImporter::class, isb_docRow('MAV-1', '28', '110', 'MAV1'), $this->user->id);
    isb_run(DocumentImporter::class, isb_docRow('MAV-2', '28', '110', 'MAV1'), $this->user->id);
    isb_run(DocumentImporter::class, isb_docRow('MAV-3', '28', '143', 'MAV1'), $this->user->id);
    isb_run(DocumentImporter::class, ['Document Identifier' => 'MAV-4', 'Series' => 'REG', 'In Situ Box 1' => 'MAV1'], $this->user->id); // the untraceable one

    $mav = isb_boxes('MAV', '1');
    expect($mav)->toHaveCount(1)
        ->and($mav->first()->parents()->pluck('boxes.id')->sort()->values()->all())->toBe([$b110->id, $b143->id]);

    Livewire::test(ListBoxes::class)
        ->assertTableColumnFormattedStateSet('parent_box_id', 'Assembled from RAS 110 (batch 28) · AA00110, RAS 143 (batch 28) · AA00143', $mav->first());
});

it('finds the parent by a barcode the RAS box had before its current one — the In Situ sheet of 2026-10-07', function (): void {
    // RAS 215 of batch 28 was AC12262, then got AA20580 (box sheet loaded per barcode generation).
    $ras = isb_ras($this->b28->id, '215', 'AC12262');
    $ras->update(['barcode' => 'AA20580']);

    isb_run(BoxImporter::class, isb_boxRow(['Box number' => '100', 'Parent box number' => 'AC12262']), $this->user->id);

    expect(isb_boxes('NRA', '100')->first()?->parent_box_id)->toBe($ras->id);
});

it('names the value when Parent box number matches no RAS box', function (): void {
    $message = '';

    try {
        isb_run(BoxImporter::class, isb_boxRow(['Box number' => '101', 'Parent box number' => 'ZZ99999']), $this->user->id);
    } catch (ValidationException $e) {
        $message = implode(' ', $e->validator->errors()->all());
    }

    expect($message)->toContain('"ZZ99999" matches no RAS box')
        ->and(isb_boxes('NRA', '101'))->toHaveCount(0);
});

/**
 * Client 2026-10-07: "I don't think this import is showing in boxes." She
 * imports with "All repositories" (no default repository): 634 In Situ boxes
 * got neither batch nor repository and were hidden from every list.
 */
function isb_runAs(User $user, array $row, array $options = []): void
{
    // The import runs in a queued job: nobody is signed in there.
    auth()->logout();
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'f.xlsx', 'file_path' => '/tmp/f.xlsx',
        'importer' => BoxImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $user->id,
    ]);
    (new BoxImporter($import, ImportWizard::guessColumnMap(BoxImporter::class, array_keys($row)), $options))($row);
}

function isb_allRepositoriesUser(): User
{
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => null]);
    $u->assignRole('super_admin');

    return $u;
}

it('gives a batch-less box its parent\'s repository when the importer has no default repository', function (): void {
    $other = Repository::factory()->create(['code' => 'EXT']);
    Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '900', 'repository_id' => $other->id]);
    $ras = isb_ras($this->b3->id, '121', 'AA00121');

    isb_runAs(isb_allRepositoriesUser(), isb_boxRow(['Parent box number' => 'AA00121']));

    expect(isb_boxes('NRA', '512')->first()?->repository_id)->toBe($this->repo->id);
});

it('uses the repository active in the top bar when the import was started', function (): void {
    $other = Repository::factory()->create(['code' => 'EXT']);
    Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '900', 'repository_id' => $other->id]);

    isb_runAs(isb_allRepositoriesUser(), isb_boxRow(['Provenance Unknown' => 'Yes']), ['repository_id' => $other->id]);

    expect(isb_boxes('NRA', '512')->first()?->repository_id)->toBe($other->id);
});

it('falls back to the only repository holding batches', function (): void {
    Repository::factory()->create(['code' => 'EXT']); // exists, but empty

    isb_runAs(isb_allRepositoriesUser(), isb_boxRow(['Provenance Unknown' => 'Yes']));

    expect(isb_boxes('NRA', '512')->first()?->repository_id)->toBe($this->repo->id);
});

it('refuses a batch-less box whose repository cannot be told, instead of hiding it', function (): void {
    $other = Repository::factory()->create(['code' => 'EXT']);
    Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '900', 'repository_id' => $other->id]);

    $message = '';

    try {
        isb_runAs(isb_allRepositoriesUser(), isb_boxRow(['Provenance Unknown' => 'Yes']));
    } catch (ValidationException $e) {
        $message = implode(' ', $e->validator->errors()->all());
    }

    expect($message)->toContain('needs a repository')
        ->and(Box::withoutGlobalScopes()->whereNull('batch_id')->whereNull('repository_id')->count())->toBe(0);
});
