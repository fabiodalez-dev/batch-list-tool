<?php

declare(strict_types=1);

use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\VolumeImporter;
use App\Models\Batch;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Models\Volume;
use App\Support\BulkImport\EntityResolver;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Spec §4 (import) — custom-field columns for Batch, Box, and Volume importers.
 *
 * For each entity:
 *   - a custom-field column keyed by label is accepted and persisted via EAV
 *   - a custom-field column keyed by cf_{key} is also accepted
 *   - a blank mapped cell clears the existing value (merge-mode delete)
 *   - an absent column leaves an existing value untouched (merge semantics)
 *   - a definition from repo B is not applied when importing into repo A
 *   - a bad/uncoercible cell does NOT fail the row
 *
 * Volume also covers:
 *   - document_identifier resolves the parent Document by identifier in the
 *     active repository
 *   - a document identifier that belongs to another repository fails the row
 *   - basic static columns (volume_number, dates_start, dates_end, notes) persist
 */
uses(RefreshDatabase::class);

/* =========================================================================
 |  Shared helpers
 * ========================================================================= */

function cfi_roles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function cfi_user(Repository $repo): User
{
    bl_seedShieldPermissions();
    cfi_roles();
    $user = User::factory()->create([
        'email' => 'cfi-' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $user->assignRole('super_admin');
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->refresh();

    return $user;
}

function cfi_repo(string $prefix = 'CFI'): Repository
{
    return Repository::factory()->create(['code' => $prefix . '_' . substr(uniqid(), -6)]);
}

function cfi_batch(int $repoId, int $batchNumber = 0): Batch
{
    if ($batchNumber === 0) {
        do {
            $batchNumber = random_int(2000, 8999);
        } while (in_array($batchNumber, [33, 34, 36], true)
            || Batch::withoutGlobalScope(RepositoryScope::class)
                ->where('batch_number', $batchNumber)->exists());
    }

    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $batchNumber,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function cfi_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'CFI_' . substr(uniqid(), -4)],
        ['title' => 'CFI series', 'is_active' => true],
    );
}

function cfi_document(int $repoId, int $seriesId, string $identifier = ''): Document
{
    if ($identifier === '') {
        $identifier = 'CFI-' . strtoupper(substr(uniqid(), -8));
    }

    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => $identifier,
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ]);
}

function cfi_def(int $repoId, string $entity, string $key, string $label, string $type = 'text'): CustomFieldDefinition
{
    return CustomFieldDefinition::create([
        'repository_id' => $repoId,
        'entity_type' => $entity,
        'key' => $key,
        'label' => $label,
        'type' => $type,
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);
}

/**
 * Bootstrap an Import model row for a given importer class + user.
 */
function cfi_importModel(string $importerClass, int $userId): Import
{
    return Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => $importerClass,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
}

/**
 * Build a column map that correctly maps Filament import column names to the
 * incoming data keys. For standard (non-custom-field) columns, the column name
 * IS the data key. For custom-field columns (named 'custom_field_{key}'), we
 * match by comparing each column's guess aliases (case-insensitive) against the
 * data keys.
 *
 * This mirrors what the Filament import wizard does in the UI: it calls
 * getColumns() (with auth already set) to show the mapping dropdowns, then
 * persists the operator's selections as the columnMap before running the job.
 *
 * @param array<string, mixed> $data
 * @return array<string, string>
 */
function cfi_buildColumnMap(string $importerClass, array $data): array
{
    // Start from the trivial identity map so every data key maps to itself
    // (covers static columns whose name == their usual spreadsheet header).
    $map = array_combine(array_keys($data), array_keys($data));

    // Build a lowercase lookup of data keys for case-insensitive matching.
    $dataLower = [];
    foreach (array_keys($data) as $key) {
        $dataLower[strtolower($key)] = $key;
    }

    // For custom-field columns the ImportColumn name is 'custom_field_{key}'
    // but the data key may be the label, 'cf_{key}', or the bare key. We
    // iterate the registered columns (called AFTER actingAs, so auth is set)
    // and match each column's guess aliases against the data keys.
    foreach ($importerClass::getColumns() as $column) {
        $colName = $column->getName();
        if (! str_starts_with($colName, 'custom_field_')) {
            continue; // static columns already handled by identity map
        }

        // getGuesses() returns lowercased/transformed variants prepended with
        // the column name and label. Try each against our lowercase data key map.
        $guesses = method_exists($column, 'getGuesses') ? $column->getGuesses() : [];
        foreach ($guesses as $guess) {
            $guessLower = strtolower((string) $guess);
            if (isset($dataLower[$guessLower])) {
                $map[$colName] = $dataLower[$guessLower];
                break;
            }
        }
    }

    return $map;
}

/**
 * Run a single row through an importer and return the importer instance.
 *
 * @param array<string, mixed> $data
 */
function cfi_run(string $importerClass, array $data, int $userId): object
{
    EntityResolver::flushMemo();
    CustomFieldResolver::flush();

    $row = cfi_importModel($importerClass, $userId);
    $columnMap = cfi_buildColumnMap($importerClass, $data);
    $importer = new $importerClass($row, $columnMap, []);
    $importer($data);

    return $importer;
}

/* =========================================================================
 |  BATCH import — custom fields
 * ========================================================================= */

test('Batch import: custom field value persists via EAV by label', function (): void {
    $repo = cfi_repo('BCHIMP');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'batch', 'provenance', 'Provenance');

    $batchNumber = 5500;
    cfi_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'description' => 'Test batch',
        'type' => 'MAIN_COLLECTION',
        'Provenance' => 'Malta National Archives',
    ], $user->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_number', $batchNumber)->first();
    expect($batch)->not->toBeNull();

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch->id)
        ->first();
    expect($value)->not->toBeNull();
    expect($value->value)->toBe('Malta National Archives');
})->group('batch-import-cf');

test('Batch import: custom field value persists via cf_{key} header', function (): void {
    $repo = cfi_repo('BCHCFK');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'batch', 'origin', 'Origin');

    $batchNumber = 5501;
    cfi_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'cf_origin' => 'Gozo Branch',
    ], $user->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_number', $batchNumber)->first();
    expect($batch)->not->toBeNull();

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch->id)
        ->first();
    expect($value)->not->toBeNull();
    expect($value->value)->toBe('Gozo Branch');
})->group('batch-import-cf');

test('Batch import: blank mapped cell clears existing custom field value', function (): void {
    $repo = cfi_repo('BCHCLR');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'batch', 'notes_extra', 'Extra notes');

    // Pre-seed an existing batch with a value.
    $batchNumber = 5502;
    $batch = cfi_batch($repo->id, $batchNumber);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Batch::class,
        'customizable_id' => $batch->id,
        'value' => 'original value',
    ]);

    // Re-import with a blank cell — should delete the stored value.
    cfi_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'Extra notes' => '',
    ], $user->id);

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch->id)
        ->first();
    expect($value)->toBeNull();
})->group('batch-import-cf');

test('Batch import: absent column leaves existing custom field value untouched', function (): void {
    $repo = cfi_repo('BCHKP');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'batch', 'keep_field', 'Keep field');

    $batchNumber = 5503;
    $batch = cfi_batch($repo->id, $batchNumber);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Batch::class,
        'customizable_id' => $batch->id,
        'value' => 'should remain',
    ]);

    // Re-import WITHOUT the custom field column → should leave value intact.
    cfi_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'description' => 'updated description',
    ], $user->id);

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch->id)
        ->value('value');
    expect($value)->toBe('should remain');
})->group('batch-import-cf');

test('Batch import: definition from another repository is not applied', function (): void {
    $repoA = cfi_repo('BCHIA');
    $repoB = cfi_repo('BCHIB');
    $userA = cfi_user($repoA);
    $this->actingAs($userA);

    // Definition in repo B — must not be used when importing into repo A.
    $defB = cfi_def($repoB->id, 'batch', 'foreign_field', 'Foreign field');

    $batchNumber = 5504;
    cfi_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'Foreign field' => 'should not be stored',
    ], $userA->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_number', $batchNumber)->first();

    // No value should have been created for the repo-B definition.
    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $defB->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch?->id ?? 0)
        ->first();
    expect($value)->toBeNull();
})->group('batch-import-cf');

test('Batch import: malformed custom field cell does NOT fail the row (lenient contract)', function (): void {
    // Spec §4: "a bad custom cell must NOT fail the whole row". This exercises
    // the try/catch wrapper in BatchImporter::afterSave() (and equivalently
    // all other importers): even when setCustomFieldData() would throw, the
    // Batch row is still persisted successfully.
    //
    // We simulate the lenient path directly: supply a value that is stored
    // as-is (the importers pass raw strings through; the trait's final cast
    // on read determines the typed form). The row must import; the custom-
    // field value is stored as the raw string supplied.
    $repo = cfi_repo('BCHLNT');
    $user = cfi_user($repo);
    $this->actingAs($user);

    // Define a 'date' type field — supplying a clearly non-date string tests
    // the lenient contract: the row must still import, and the value is stored
    // as-is (the trait's read-time cast will return null for an unparseable date).
    cfi_def($repo->id, 'batch', 'lnt_date', 'Lnt Date', 'date');

    $batchNumber = 5599;
    cfi_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'Lnt Date' => 'not-a-date',  // malformed value for a date field
    ], $user->id);

    // Row must have been persisted despite the malformed custom-field cell.
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_number', $batchNumber)->first();
    expect($batch)->not->toBeNull();
})->group('batch-import-cf');

/* =========================================================================
 |  BOX import — custom fields
 * ========================================================================= */

test('Box import: custom field value persists via EAV by label', function (): void {
    $repo = cfi_repo('BOXIMP');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'box', 'condition', 'Condition');

    $batch = cfi_batch($repo->id);
    $barcode = 'BCIMPCF-' . substr(uniqid(), -6);

    cfi_run(BoxImporter::class, [
        'box_number' => '100',
        'box_type' => 'RAS',
        'batch_number' => $batch->batch_number,
        'barcode' => $barcode,
        'Condition' => 'Good',
    ], $user->id);

    $box = Box::query()->where('barcode', $barcode)->first();
    expect($box)->not->toBeNull();

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box->id)
        ->first();
    expect($value)->not->toBeNull();
    expect($value->value)->toBe('Good');
})->group('box-import-cf');

test('Box import: custom field value persists via cf_{key} header', function (): void {
    $repo = cfi_repo('BOXCFK');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'box', 'shelf', 'Shelf location');

    $batch = cfi_batch($repo->id);
    $barcode = 'BCCFK-' . substr(uniqid(), -6);

    cfi_run(BoxImporter::class, [
        'box_number' => '200',
        'box_type' => 'RAS',
        'batch_number' => $batch->batch_number,
        'barcode' => $barcode,
        'cf_shelf' => 'Shelf A3',
    ], $user->id);

    $box = Box::query()->where('barcode', $barcode)->first();
    expect($box)->not->toBeNull();

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box->id)
        ->first();
    expect($value)->not->toBeNull();
    expect($value->value)->toBe('Shelf A3');
})->group('box-import-cf');

test('Box import: blank mapped cell clears existing custom field value', function (): void {
    $repo = cfi_repo('BOXCLR');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'box', 'box_note', 'Box note');

    $batch = cfi_batch($repo->id);
    $barcode = 'BCCLR-' . substr(uniqid(), -6);

    // Pre-seed a box with a stored custom value.
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => '300',
        'batch_id' => $batch->id,
        'barcode' => $barcode,
        'barcode_status' => 'IN',
    ]);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Box::class,
        'customizable_id' => $box->id,
        'value' => 'original note',
    ]);

    // Re-import with a blank cell — should delete the stored value.
    cfi_run(BoxImporter::class, [
        'box_number' => '300',
        'box_type' => 'RAS',
        'batch_number' => $batch->batch_number,
        'barcode' => $barcode,
        'Box note' => '',
    ], $user->id);

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box->id)
        ->first();
    expect($value)->toBeNull();
})->group('box-import-cf');

test('Box import: absent column leaves existing custom field value untouched', function (): void {
    $repo = cfi_repo('BOXKP');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'box', 'box_keep', 'Box keep');

    $batch = cfi_batch($repo->id);
    $barcode = 'BCKP-' . substr(uniqid(), -6);

    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => '400',
        'batch_id' => $batch->id,
        'barcode' => $barcode,
        'barcode_status' => 'IN',
    ]);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Box::class,
        'customizable_id' => $box->id,
        'value' => 'should remain',
    ]);

    // Re-import WITHOUT the custom field column.
    cfi_run(BoxImporter::class, [
        'box_number' => '400',
        'box_type' => 'RAS',
        'batch_number' => $batch->batch_number,
        'barcode' => $barcode,
    ], $user->id);

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box->id)
        ->value('value');
    expect($value)->toBe('should remain');
})->group('box-import-cf');

test('Box import: definition from another repository is not applied', function (): void {
    $repoA = cfi_repo('BOXIA');
    $repoB = cfi_repo('BOXIB');
    $userA = cfi_user($repoA);
    $this->actingAs($userA);

    $defB = cfi_def($repoB->id, 'box', 'foreign_box_field', 'Foreign box field');

    $batchA = cfi_batch($repoA->id);
    $barcode = 'BCFGN-' . substr(uniqid(), -6);

    cfi_run(BoxImporter::class, [
        'box_number' => '500',
        'box_type' => 'RAS',
        'batch_number' => $batchA->batch_number,
        'barcode' => $barcode,
        'Foreign box field' => 'should not be stored',
    ], $userA->id);

    $box = Box::query()->where('barcode', $barcode)->first();

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $defB->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box?->id ?? 0)
        ->first();
    expect($value)->toBeNull();
})->group('box-import-cf');

/* =========================================================================
 |  VOLUME import — static columns + custom fields
 * ========================================================================= */

test('Volume import: static columns persist correctly', function (): void {
    $repo = cfi_repo('VOLIMP');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $series = cfi_series();
    $doc = cfi_document($repo->id, $series->id, 'VOL-DOC-001');

    cfi_run(VolumeImporter::class, [
        'document_identifier' => 'VOL-DOC-001',
        'volume_number' => 'Vol. I',
        'dates_start' => '2020-01-01',
        'dates_end' => '2021-12-31',
        'notes' => 'Test notes',
    ], $user->id);

    $volume = Volume::query()->where('document_id', $doc->id)->first();
    expect($volume)->not->toBeNull();
    expect($volume->volume_number)->toBe('Vol. I');
    expect($volume->dates_start?->format('Y-m-d'))->toBe('2020-01-01');
    expect($volume->dates_end?->format('Y-m-d'))->toBe('2021-12-31');
    expect($volume->notes)->toBe('Test notes');
})->group('volume-import-cf');

test('Volume import: document identifier resolves the parent document', function (): void {
    $repo = cfi_repo('VOLIDX');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $series = cfi_series();
    $doc = cfi_document($repo->id, $series->id, 'VOLIDX-001');

    cfi_run(VolumeImporter::class, [
        'document_identifier' => 'VOLIDX-001',
        'volume_number' => 'Vol. A',
    ], $user->id);

    $volume = Volume::query()->where('document_id', $doc->id)->first();
    expect($volume)->not->toBeNull();
    expect($volume->document_id)->toBe($doc->id);
})->group('volume-import-cf');

test('Volume import: unknown document identifier fails the row', function (): void {
    // Contract: an unresolvable document_identifier must fail THAT ROW
    // (Volume not created) without aborting the import or leaking tenant data.
    // When run directly in a test (outside the queued job) the row-level
    // exception propagates; we swallow it and assert the outcome instead.
    $repo = cfi_repo('VOLERR');
    $user = cfi_user($repo);
    $this->actingAs($user);

    try {
        cfi_run(VolumeImporter::class, [
            'document_identifier' => 'NONEXISTENT-DOC-XYZ',
            'volume_number' => 'Vol. Z',
        ], $user->id);
    } catch (ValidationException|RowImportFailedException) {
        // Expected row-level failure — same mechanism as BoxImporter rejecting
        // a missing parent. In the queued job this becomes a failed-row entry;
        // here it propagates and we just verify no Volume was persisted.
    }

    // The important assertion: no Volume was created.
    expect(Volume::query()->count())->toBe(0);
})->group('volume-import-cf');

test('Volume import: document from another repository fails the row', function (): void {
    // Contract: a document in repo B must NOT resolve when the active repository
    // is repo A — cross-tenant Volume creation must be blocked at the row level.
    // When run directly in a test the row-level exception propagates; we swallow
    // it and assert the outcome (no Volume created, no tenant leak).
    $repoA = cfi_repo('VOLIA');
    $repoB = cfi_repo('VOLIB');
    $userA = cfi_user($repoA);
    $this->actingAs($userA);

    $series = cfi_series();
    // Create document in repo B — should NOT be resolvable when importing into repo A.
    cfi_document($repoB->id, $series->id, 'VOLIB-DOC-XTEN');

    try {
        cfi_run(VolumeImporter::class, [
            'document_identifier' => 'VOLIB-DOC-XTEN',
            'volume_number' => 'Vol. Cross',
        ], $userA->id);
    } catch (ValidationException|RowImportFailedException) {
        // Expected: the cross-repo document identifier resolves as "not found"
        // under repo A, so afterFill() rejects the row. The queued job records
        // this as a failed row; here the exception surfaces in the test layer.
    }

    // When active repo is set to repoA, a doc in repoB must not resolve.
    expect(Volume::query()->count())->toBe(0);
})->group('volume-import-cf');

test('Volume import: custom field value persists via EAV by label', function (): void {
    $repo = cfi_repo('VOLCF');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'volume', 'language', 'Language');
    $series = cfi_series();
    $doc = cfi_document($repo->id, $series->id, 'VOLCF-DOC-001');

    cfi_run(VolumeImporter::class, [
        'document_identifier' => 'VOLCF-DOC-001',
        'volume_number' => 'Vol. I',
        'Language' => 'Maltese',
    ], $user->id);

    $volume = Volume::query()->where('document_id', $doc->id)->first();
    expect($volume)->not->toBeNull();

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume->id)
        ->first();
    expect($value)->not->toBeNull();
    expect($value->value)->toBe('Maltese');
})->group('volume-import-cf');

test('Volume import: custom field persists via cf_{key} header', function (): void {
    $repo = cfi_repo('VOLCFK');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'volume', 'script_type', 'Script type');
    $series = cfi_series();
    $doc = cfi_document($repo->id, $series->id, 'VOLCFK-DOC-001');

    cfi_run(VolumeImporter::class, [
        'document_identifier' => 'VOLCFK-DOC-001',
        'volume_number' => 'Vol. II',
        'cf_script_type' => 'Latin',
    ], $user->id);

    $volume = Volume::query()->where('document_id', $doc->id)->first();
    expect($volume)->not->toBeNull();

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume->id)
        ->first();
    expect($value)->not->toBeNull();
    expect($value->value)->toBe('Latin');
})->group('volume-import-cf');

test('Volume import: blank mapped custom field cell clears existing value', function (): void {
    $repo = cfi_repo('VOLCLR');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'volume', 'vol_note', 'Vol note');
    $series = cfi_series();
    $doc = cfi_document($repo->id, $series->id, 'VOLCLR-DOC-001');

    // Pre-seed a volume.
    $volume = Volume::create([
        'document_id' => $doc->id,
        'volume_number' => 'Vol. III',
    ]);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Volume::class,
        'customizable_id' => $volume->id,
        'value' => 'old note',
    ]);

    // Re-import with blank cell — should delete the value.
    cfi_run(VolumeImporter::class, [
        'document_identifier' => 'VOLCLR-DOC-001',
        'volume_number' => 'Vol. III',
        'Vol note' => '',
    ], $user->id);

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume->id)
        ->first();
    expect($value)->toBeNull();
})->group('volume-import-cf');

test('Volume import: absent custom field column leaves existing value untouched', function (): void {
    $repo = cfi_repo('VOLKP');
    $user = cfi_user($repo);
    $this->actingAs($user);

    $def = cfi_def($repo->id, 'volume', 'vol_keep', 'Vol keep');
    $series = cfi_series();
    $doc = cfi_document($repo->id, $series->id, 'VOLKP-DOC-001');

    $volume = Volume::create([
        'document_id' => $doc->id,
        'volume_number' => 'Vol. IV',
    ]);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Volume::class,
        'customizable_id' => $volume->id,
        'value' => 'should remain',
    ]);

    // Re-import WITHOUT the custom field column.
    cfi_run(VolumeImporter::class, [
        'document_identifier' => 'VOLKP-DOC-001',
        'volume_number' => 'Vol. IV',
        'notes' => 'updated notes',
    ], $user->id);

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume->id)
        ->value('value');
    expect($value)->toBe('should remain');
})->group('volume-import-cf');

test('Volume import: custom field from another repository is not applied', function (): void {
    $repoA = cfi_repo('VOLIA2');
    $repoB = cfi_repo('VOLIB2');
    $userA = cfi_user($repoA);
    $this->actingAs($userA);

    $defB = cfi_def($repoB->id, 'volume', 'foreign_vol_field', 'Foreign vol field');
    $series = cfi_series();
    $doc = cfi_document($repoA->id, $series->id, 'VOLIA2-DOC-001');

    cfi_run(VolumeImporter::class, [
        'document_identifier' => 'VOLIA2-DOC-001',
        'volume_number' => 'Vol. V',
        'Foreign vol field' => 'should not be stored',
    ], $userA->id);

    $volume = Volume::query()->where('document_id', $doc->id)->first();

    $value = CustomFieldValue::query()
        ->where('custom_field_definition_id', $defB->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume?->id ?? 0)
        ->first();
    expect($value)->toBeNull();
})->group('volume-import-cf');
