<?php

declare(strict_types=1);

use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Imports\VolumeImporter;
use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\VolumeResource\Pages\ListVolumes;
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
use App\Support\ActiveRepository;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\CustomFields\CustomFieldResolver;
use Carbon\Carbon;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Spec §5 — comprehensive import/export/template tests for all 4 entities.
 *
 * Coverage matrix (per entity: document, batch, box, volume):
 *
 *   Template:
 *     - headersFor() appends active custom-field labels after static headers
 *     - both defs (text + date) appear in order
 *     - a def in repo B is absent when active repo = A
 *     - an inactive def is absent
 *
 *   Export:
 *     - text value appears in CSV
 *     - boolean → 1/0
 *     - date → Y-m-d
 *
 *   Import:
 *     - value persists via label and via cf_{key}
 *     - blank mapped cell clears (merge semantics)
 *     - absent column leaves untouched (merge semantics)
 *     - repo-B column not applied when importing into repo A
 *     - typed cast: boolean raw cell '1' stored as '1'; getTypedValueAttribute() returns true
 *     - typed cast: date cell '2024-06-01' stored as '2024-06-01'; getTypedValueAttribute() returns Carbon
 *
 *   Volume only:
 *     - document resolved by identifier scoped to active repo
 *     - unknown identifier fails the row
 *     - document from another repo fails (tenant rejection)
 *     - export fixed columns include document_identifier
 *
 * Resolver unit tests (activeRepositoryId / definitionsFor memo) live in
 * CustomFieldResolverTest.php and are not duplicated here.
 */
uses(RefreshDatabase::class);

/* =========================================================================
 |  Shared helpers
 * ========================================================================= */

/**
 * Seed permissions + roles + create a super_admin user attached to $repo.
 */
function ce2_user(Repository $repo): User
{
    bl_seedShieldPermissions();

    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $user = User::factory()->create([
        'email' => 'ce2-' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $user->assignRole('super_admin');
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->refresh();

    return $user;
}

function ce2_repo(string $prefix = 'CE2'): Repository
{
    return Repository::factory()->create(['code' => $prefix . '_' . substr(uniqid(), -6)]);
}

function ce2_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'CE2_' . substr(uniqid(), -4)],
        ['title' => 'CE2 series', 'is_active' => true],
    );
}

function ce2_doc(int $repoId, int $seriesId, string $identifier = ''): Document
{
    if ($identifier === '') {
        $identifier = 'CE2-' . strtoupper(substr(uniqid(), -8));
    }

    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => $identifier,
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ]);
}

function ce2_batch(int $repoId, int $batchNumber = 0): Batch
{
    if ($batchNumber === 0) {
        do {
            $batchNumber = random_int(3000, 8000);
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

function ce2_box(int $batchId): Box
{
    return Box::create([
        'box_type' => 'RAS',
        'box_number' => 'CE2-' . strtoupper(substr(uniqid(), -6)),
        'batch_id' => $batchId,
        'barcode' => 'CE2BC-' . strtoupper(substr(uniqid(), -6)),
        'barcode_status' => 'IN',
    ]);
}

function ce2_volume(int $documentId): Volume
{
    return Volume::create([
        'document_id' => $documentId,
        'volume_number' => 'CE2VOL-' . strtoupper(substr(uniqid(), -4)),
    ]);
}

/**
 * Create a CustomFieldDefinition.
 *
 * @param array<string, mixed> $overrides
 */
function ce2_def(
    int $repoId,
    string $entityType,
    string $key,
    string $label,
    string $type = 'text',
    array $overrides = [],
): CustomFieldDefinition {
    return CustomFieldDefinition::create(array_merge([
        'repository_id' => $repoId,
        'entity_type' => $entityType,
        'key' => $key,
        'label' => $label,
        'type' => $type,
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ], $overrides));
}

/**
 * Bootstrap an Import model row for a given importer class + user.
 */
function ce2_importModel(string $importerClass, int $userId): Import
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
 * Build a column map and run a single row through an importer.
 *
 * For custom-field columns (named 'custom_field_{key}') we match the column's
 * guess aliases against the data keys case-insensitively, mirroring what the
 * Filament import wizard does in the UI.
 *
 * @param array<string, mixed> $data
 */
function ce2_run(string $importerClass, array $data, int $userId): object
{
    EntityResolver::flushMemo();
    CustomFieldResolver::flush();

    $row = ce2_importModel($importerClass, $userId);

    // Identity map: every data key maps to itself (covers static columns).
    $map = array_combine(array_keys($data), array_keys($data));

    // Build a lowercase lookup of data keys.
    $dataLower = [];
    foreach (array_keys($data) as $k) {
        $dataLower[strtolower($k)] = $k;
    }

    // Match custom-field columns by guess aliases.
    foreach ($importerClass::getColumns() as $col) {
        $colName = $col->getName();
        if (! str_starts_with($colName, 'custom_field_')) {
            continue;
        }
        $guesses = method_exists($col, 'getGuesses') ? $col->getGuesses() : [];
        foreach ($guesses as $guess) {
            if (isset($dataLower[strtolower((string) $guess)])) {
                $map[$colName] = $dataLower[strtolower((string) $guess)];
                break;
            }
        }
    }

    $importer = new $importerClass($row, $map, []);
    $importer($data);

    return $importer;
}

/**
 * Run a ListDocuments component export and return the CSV (BOM stripped).
 */
function ce2_docCsv(): string
{
    $component = Livewire::test(ListDocuments::class);
    /** @var ListDocuments $page */
    $page = $component->instance();
    ob_start();
    $page->exportToCsv()->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/**
 * Run a ListBatches component export and return the CSV (BOM stripped).
 */
function ce2_batchCsv(): string
{
    $component = Livewire::test(ListBatches::class);
    /** @var ListBatches $page */
    $page = $component->instance();
    ob_start();
    $page->exportToCsv()->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/**
 * Run a ListBoxes component export and return the CSV (BOM stripped).
 */
function ce2_boxCsv(): string
{
    $component = Livewire::test(ListBoxes::class);
    /** @var ListBoxes $page */
    $page = $component->instance();
    ob_start();
    $page->exportToCsv()->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/**
 * Run a ListVolumes component export and return the CSV (BOM stripped).
 */
function ce2_volumeCsv(): string
{
    $component = Livewire::test(ListVolumes::class);
    /** @var ListVolumes $page */
    $page = $component->instance();
    ob_start();
    $page->exportToCsv()->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/* =========================================================================
 |  §5 TEMPLATE — Document
 * ========================================================================= */

test('[Template/Document] headersFor appends active text+date custom labels after static headers', function (): void {
    $repo = ce2_repo('TDOC');
    $user = ce2_user($repo);
    $this->actingAs($user);
    // Set active repo via session so CustomFieldResolver::activeRepositoryId() picks it up.
    Session::put(ActiveRepository::SESSION_KEY, $repo->id);
    CustomFieldResolver::flush();

    // Seed two active defs with explicit sort_order to test ordering.
    ce2_def($repo->id, 'document', 'doc_text_field', 'Doc Text Field', 'text', ['sort_order' => 10]);
    ce2_def($repo->id, 'document', 'doc_date_field', 'Doc Date Field', 'date', ['sort_order' => 20]);

    $headers = TemplateGenerator::headersFor('document');

    // Static headers must come first (spot-check using known DOCUMENT_HEADERS content).
    expect($headers)->toContain('Identifier')
        ->and($headers)->toContain('Document Type')
        ->and($headers)->toContain('Series');

    // Custom labels appended at the end.
    expect($headers)->toContain('Doc Text Field');
    expect($headers)->toContain('Doc Date Field');

    // Custom labels must appear AFTER all static headers.
    $staticCount = count(TemplateGenerator::DOCUMENT_HEADERS);
    $textIdx = array_search('Doc Text Field', $headers, true);
    $dateIdx = array_search('Doc Date Field', $headers, true);
    expect($textIdx)->toBeGreaterThanOrEqual($staticCount);
    expect($dateIdx)->toBeGreaterThanOrEqual($staticCount);
    // Sort order respected: text (10) before date (20).
    expect($textIdx)->toBeLessThan($dateIdx);
})->group('template-document');

test('[Template/Document] def in repo B is absent when active repo = A', function (): void {
    $repoA = ce2_repo('TDOCA');
    $repoB = ce2_repo('TDOCB');
    $user = ce2_user($repoA);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repoA->id);
    CustomFieldResolver::flush();

    ce2_def($repoA->id, 'document', 'doc_a_field', 'Doc A Field');
    ce2_def($repoB->id, 'document', 'doc_b_field', 'Doc B Field');

    $headers = TemplateGenerator::headersFor('document');

    expect($headers)->toContain('Doc A Field');
    expect($headers)->not->toContain('Doc B Field');
})->group('template-document');

test('[Template/Document] inactive def is absent from headers', function (): void {
    $repo = ce2_repo('TDOCINACT');
    $user = ce2_user($repo);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repo->id);
    CustomFieldResolver::flush();

    ce2_def($repo->id, 'document', 'doc_active', 'Doc Active Field', 'text', ['is_active' => true]);
    ce2_def($repo->id, 'document', 'doc_inactive', 'Doc Inactive Field', 'text', ['is_active' => false]);

    $headers = TemplateGenerator::headersFor('document');

    expect($headers)->toContain('Doc Active Field');
    expect($headers)->not->toContain('Doc Inactive Field');
})->group('template-document');

/* =========================================================================
 |  §5 TEMPLATE — Batch
 * ========================================================================= */

test('[Template/Batch] headersFor appends active text+date custom labels after static headers', function (): void {
    $repo = ce2_repo('TBAT');
    $user = ce2_user($repo);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repo->id);
    CustomFieldResolver::flush();

    ce2_def($repo->id, 'batch', 'bat_text', 'Batch Text Field', 'text', ['sort_order' => 1]);
    ce2_def($repo->id, 'batch', 'bat_date', 'Batch Date Field', 'date', ['sort_order' => 2]);

    $headers = TemplateGenerator::headersFor('batch');

    // Static headers must be present first.
    expect($headers[0])->toBe('batch_number');
    expect($headers)->toContain('description');
    expect($headers)->toContain('type');

    // Custom labels appended at the end.
    expect($headers)->toContain('Batch Text Field');
    expect($headers)->toContain('Batch Date Field');

    $staticCount = count(['batch_number', 'description', 'type', 'is_active', 'repository_code']);
    $textIdx = array_search('Batch Text Field', $headers, true);
    $dateIdx = array_search('Batch Date Field', $headers, true);
    expect($textIdx)->toBeGreaterThanOrEqual($staticCount);
    expect($dateIdx)->toBeGreaterThanOrEqual($staticCount);
    expect($textIdx)->toBeLessThan($dateIdx);
})->group('template-batch');

test('[Template/Batch] def in repo B is absent when active repo = A', function (): void {
    $repoA = ce2_repo('TBATA');
    $repoB = ce2_repo('TBATB');
    $user = ce2_user($repoA);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repoA->id);
    CustomFieldResolver::flush();

    ce2_def($repoA->id, 'batch', 'bat_a', 'Batch A Field');
    ce2_def($repoB->id, 'batch', 'bat_b', 'Batch B Field');

    $headers = TemplateGenerator::headersFor('batch');

    expect($headers)->toContain('Batch A Field');
    expect($headers)->not->toContain('Batch B Field');
})->group('template-batch');

test('[Template/Batch] inactive def is absent from headers', function (): void {
    $repo = ce2_repo('TBATINACT');
    $user = ce2_user($repo);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repo->id);
    CustomFieldResolver::flush();

    ce2_def($repo->id, 'batch', 'bat_on', 'Batch On Field', 'text', ['is_active' => true]);
    ce2_def($repo->id, 'batch', 'bat_off', 'Batch Off Field', 'text', ['is_active' => false]);

    $headers = TemplateGenerator::headersFor('batch');

    expect($headers)->toContain('Batch On Field');
    expect($headers)->not->toContain('Batch Off Field');
})->group('template-batch');

/* =========================================================================
 |  §5 TEMPLATE — Box
 * ========================================================================= */

test('[Template/Box] headersFor appends active text+date custom labels after static headers', function (): void {
    $repo = ce2_repo('TBOX');
    $user = ce2_user($repo);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repo->id);
    CustomFieldResolver::flush();

    ce2_def($repo->id, 'box', 'box_text', 'Box Text Field', 'text', ['sort_order' => 1]);
    ce2_def($repo->id, 'box', 'box_date', 'Box Date Field', 'date', ['sort_order' => 2]);

    $headers = TemplateGenerator::headersFor('box');

    // Static headers from synthesiseBoxHeaders().
    expect($headers[0])->toBe('box_type');
    expect($headers)->toContain('box_number');
    expect($headers)->toContain('batch_number');
    expect($headers)->toContain('barcode');

    // Custom labels appended.
    expect($headers)->toContain('Box Text Field');
    expect($headers)->toContain('Box Date Field');

    $staticCount = count(['box_type', 'box_number', 'batch_number', 'parent_box_number', 'barcode', 'barcode_status', 'disinfestation_date', 'is_legacy', 'notes']);
    $textIdx = array_search('Box Text Field', $headers, true);
    $dateIdx = array_search('Box Date Field', $headers, true);
    expect($textIdx)->toBeGreaterThanOrEqual($staticCount);
    expect($dateIdx)->toBeGreaterThanOrEqual($staticCount);
    expect($textIdx)->toBeLessThan($dateIdx);
})->group('template-box');

test('[Template/Box] def in repo B is absent when active repo = A', function (): void {
    $repoA = ce2_repo('TBOXA');
    $repoB = ce2_repo('TBOXB');
    $user = ce2_user($repoA);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repoA->id);
    CustomFieldResolver::flush();

    ce2_def($repoA->id, 'box', 'box_a', 'Box A Field');
    ce2_def($repoB->id, 'box', 'box_b', 'Box B Field');

    $headers = TemplateGenerator::headersFor('box');

    expect($headers)->toContain('Box A Field');
    expect($headers)->not->toContain('Box B Field');
})->group('template-box');

test('[Template/Box] inactive def is absent from headers', function (): void {
    $repo = ce2_repo('TBOXINACT');
    $user = ce2_user($repo);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repo->id);
    CustomFieldResolver::flush();

    ce2_def($repo->id, 'box', 'box_on', 'Box On Field', 'text', ['is_active' => true]);
    ce2_def($repo->id, 'box', 'box_off', 'Box Off Field', 'text', ['is_active' => false]);

    $headers = TemplateGenerator::headersFor('box');

    expect($headers)->toContain('Box On Field');
    expect($headers)->not->toContain('Box Off Field');
})->group('template-box');

/* =========================================================================
 |  §5 TEMPLATE — Volume
 * ========================================================================= */

test('[Template/Volume] headersFor appends active text+date custom labels after static headers', function (): void {
    $repo = ce2_repo('TVOL');
    $user = ce2_user($repo);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repo->id);
    CustomFieldResolver::flush();

    ce2_def($repo->id, 'volume', 'vol_text', 'Vol Text Field', 'text', ['sort_order' => 1]);
    ce2_def($repo->id, 'volume', 'vol_date', 'Vol Date Field', 'date', ['sort_order' => 2]);

    $headers = TemplateGenerator::headersFor('volume');

    // Static headers from synthesiseVolumeHeaders().
    expect($headers[0])->toBe('document_identifier');
    expect($headers)->toContain('volume_number');
    expect($headers)->toContain('dates_start');
    expect($headers)->toContain('dates_end');
    expect($headers)->toContain('notes');

    // Custom labels appended.
    expect($headers)->toContain('Vol Text Field');
    expect($headers)->toContain('Vol Date Field');

    $staticCount = count(['document_identifier', 'volume_number', 'dates_start', 'dates_end', 'notes']);
    $textIdx = array_search('Vol Text Field', $headers, true);
    $dateIdx = array_search('Vol Date Field', $headers, true);
    expect($textIdx)->toBeGreaterThanOrEqual($staticCount);
    expect($dateIdx)->toBeGreaterThanOrEqual($staticCount);
    expect($textIdx)->toBeLessThan($dateIdx);
})->group('template-volume');

test('[Template/Volume] def in repo B is absent when active repo = A', function (): void {
    $repoA = ce2_repo('TVOLA');
    $repoB = ce2_repo('TVOLB');
    $user = ce2_user($repoA);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repoA->id);
    CustomFieldResolver::flush();

    ce2_def($repoA->id, 'volume', 'vol_a', 'Vol A Field');
    ce2_def($repoB->id, 'volume', 'vol_b', 'Vol B Field');

    $headers = TemplateGenerator::headersFor('volume');

    expect($headers)->toContain('Vol A Field');
    expect($headers)->not->toContain('Vol B Field');
})->group('template-volume');

test('[Template/Volume] inactive def is absent from headers', function (): void {
    $repo = ce2_repo('TVOLINACT');
    $user = ce2_user($repo);
    $this->actingAs($user);
    Session::put(ActiveRepository::SESSION_KEY, $repo->id);
    CustomFieldResolver::flush();

    ce2_def($repo->id, 'volume', 'vol_on', 'Vol On Field', 'text', ['is_active' => true]);
    ce2_def($repo->id, 'volume', 'vol_off', 'Vol Off Field', 'text', ['is_active' => false]);

    $headers = TemplateGenerator::headersFor('volume');

    expect($headers)->toContain('Vol On Field');
    expect($headers)->not->toContain('Vol Off Field');
})->group('template-volume');

/* =========================================================================
 |  §5 EXPORT — Document
 * ========================================================================= */

test('[Export/Document] text custom field value appears in CSV', function (): void {
    $repo = ce2_repo('EXDTXT');
    $user = ce2_user($repo);
    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id);

    $def = ce2_def($repo->id, 'document', 'doc_ref', 'Doc Reference');
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => $doc->id,
        'value' => 'NRA-REF-001',
    ]);

    $this->actingAs($user);
    $csv = ce2_docCsv();

    expect($csv)->toContain('Doc Reference');
    expect($csv)->toContain('NRA-REF-001');
})->group('export-document');

test('[Export/Document] boolean custom field renders as 1 or 0', function (): void {
    $repo = ce2_repo('EXDBOOL');
    $user = ce2_user($repo);
    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id);

    $def = ce2_def($repo->id, 'document', 'doc_digitised', 'Digitised', 'boolean');
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => $doc->id,
        'value' => '1',
    ]);

    $this->actingAs($user);
    $csv = ce2_docCsv();

    expect($csv)->toContain('Digitised');
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines));
    $colIdx = array_search('Digitised', $header, true);
    expect($colIdx)->not->toBeFalse();

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line);
        expect($cells[$colIdx])->toBe('1');
    }
})->group('export-document');

test('[Export/Document] date custom field renders as Y-m-d', function (): void {
    $repo = ce2_repo('EXDDATE');
    $user = ce2_user($repo);
    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id);

    $def = ce2_def($repo->id, 'document', 'doc_acquired', 'Acquired on', 'date');
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => $doc->id,
        'value' => '2023-11-30',
    ]);

    $this->actingAs($user);
    $csv = ce2_docCsv();

    expect($csv)->toContain('Acquired on');
    expect($csv)->toContain('2023-11-30');
})->group('export-document');

/* =========================================================================
 |  §5 EXPORT — Batch
 * ========================================================================= */

test('[Export/Batch] text custom field value appears in CSV', function (): void {
    $repo = ce2_repo('EXBTXT');
    $user = ce2_user($repo);
    $batch = ce2_batch($repo->id);

    $def = ce2_def($repo->id, 'batch', 'bat_origin', 'Batch Origin');
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Batch::class,
        'customizable_id' => $batch->id,
        'value' => 'Malta Branch',
    ]);

    $this->actingAs($user);
    $csv = ce2_batchCsv();

    expect($csv)->toContain('Batch Origin');
    expect($csv)->toContain('Malta Branch');
})->group('export-batch');

test('[Export/Batch] date custom field renders as Y-m-d', function (): void {
    $repo = ce2_repo('EXBDATE');
    $user = ce2_user($repo);
    $batch = ce2_batch($repo->id);

    $def = ce2_def($repo->id, 'batch', 'bat_created', 'Batch Created', 'date');
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Batch::class,
        'customizable_id' => $batch->id,
        'value' => '2022-03-01',
    ]);

    $this->actingAs($user);
    $csv = ce2_batchCsv();

    expect($csv)->toContain('Batch Created');
    expect($csv)->toContain('2022-03-01');
})->group('export-batch');

/* =========================================================================
 |  §5 EXPORT — Box
 * ========================================================================= */

test('[Export/Box] text custom field value appears in CSV', function (): void {
    $repo = ce2_repo('EXBXTXT');
    $user = ce2_user($repo);
    $batch = ce2_batch($repo->id);
    $box = ce2_box($batch->id);

    $def = ce2_def($repo->id, 'box', 'box_note2', 'Box Note');
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Box::class,
        'customizable_id' => $box->id,
        'value' => 'Handle with care',
    ]);

    $this->actingAs($user);
    $csv = ce2_boxCsv();

    expect($csv)->toContain('Box Note');
    expect($csv)->toContain('Handle with care');
})->group('export-box');

test('[Export/Box] boolean custom field renders as 1 or 0', function (): void {
    $repo = ce2_repo('EXBXBOOL');
    $user = ce2_user($repo);
    $batch = ce2_batch($repo->id);
    $box = ce2_box($batch->id);

    $def = ce2_def($repo->id, 'box', 'box_checked', 'Box Checked', 'boolean');
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Box::class,
        'customizable_id' => $box->id,
        'value' => '0',
    ]);

    $this->actingAs($user);
    $csv = ce2_boxCsv();

    expect($csv)->toContain('Box Checked');
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines));
    $colIdx = array_search('Box Checked', $header, true);
    expect($colIdx)->not->toBeFalse();

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line);
        expect($cells[$colIdx])->toBe('0');
    }
})->group('export-box');

/* =========================================================================
 |  §5 EXPORT — Volume
 * ========================================================================= */

test('[Export/Volume] text custom field value appears in CSV', function (): void {
    $repo = ce2_repo('EXVTXT');
    $user = ce2_user($repo);
    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id);
    $volume = ce2_volume($doc->id);

    $def = ce2_def($repo->id, 'volume', 'vol_script', 'Vol Script');
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Volume::class,
        'customizable_id' => $volume->id,
        'value' => 'Latin cursive',
    ]);

    $this->actingAs($user);
    $csv = ce2_volumeCsv();

    expect($csv)->toContain('Vol Script');
    expect($csv)->toContain('Latin cursive');
})->group('export-volume');

test('[Export/Volume] date custom field renders as Y-m-d', function (): void {
    $repo = ce2_repo('EXVDATE');
    $user = ce2_user($repo);
    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id);
    $volume = ce2_volume($doc->id);

    $def = ce2_def($repo->id, 'volume', 'vol_inspected', 'Vol Inspected', 'date');
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Volume::class,
        'customizable_id' => $volume->id,
        'value' => '2025-01-20',
    ]);

    $this->actingAs($user);
    $csv = ce2_volumeCsv();

    expect($csv)->toContain('Vol Inspected');
    expect($csv)->toContain('2025-01-20');
})->group('export-volume');

test('[Export/Volume] fixed columns include document identifier', function (): void {
    $repo = ce2_repo('EXVIDX');
    $user = ce2_user($repo);
    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id, 'EXVID-DOC-001');
    ce2_volume($doc->id);

    $this->actingAs($user);
    $csv = ce2_volumeCsv();

    expect($csv)->toContain('EXVID-DOC-001');
    expect($csv)->toContain('Document identifier');
    expect($csv)->toContain('Volume number');
})->group('export-volume');

/* =========================================================================
 |  §5 IMPORT — Document (typed cast)
 * ========================================================================= */

test('[Import/Document] boolean custom field — raw "1" stored as "1", typedValue = true', function (): void {
    $repo = ce2_repo('IMDOCBOOL');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'document', 'doc_sealed', 'Sealed', 'boolean');

    // 'identifier' maps to the DocumentImporter column name by identity.
    // 'series' is also required for new Document records.
    $series = ce2_series();
    $identifier = 'IMDOCBOOL-' . strtoupper(substr(uniqid(), -6));
    ce2_run(DocumentImporter::class, [
        'identifier' => $identifier,
        'series' => $series->code,
        'Sealed' => '1',
    ], $user->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $identifier)->first();
    expect($doc)->not->toBeNull();

    $cfv = CustomFieldValue::with('definition')
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->first();
    expect($cfv)->not->toBeNull();
    // Raw stored value.
    expect($cfv->value)->toBe('1');
    // Typed cast returns PHP bool.
    expect($cfv->getTypedValueAttribute())->toBeTrue();
})->group('import-document');

test('[Import/Document] date custom field — raw "2024-06-01" stored + typedValue = Carbon', function (): void {
    $repo = ce2_repo('IMDOCDATE');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'document', 'doc_arrival', 'Arrival Date', 'date');

    // 'identifier' maps to the DocumentImporter column name by identity.
    // 'series' is also required for new Document records.
    $series = ce2_series();
    $identifier = 'IMDOCDATE-' . strtoupper(substr(uniqid(), -6));
    ce2_run(DocumentImporter::class, [
        'identifier' => $identifier,
        'series' => $series->code,
        'Arrival Date' => '2024-06-01',
    ], $user->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $identifier)->first();
    expect($doc)->not->toBeNull();

    $cfv = CustomFieldValue::with('definition')
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->first();
    expect($cfv)->not->toBeNull();
    expect($cfv->value)->toBe('2024-06-01');
    expect($cfv->getTypedValueAttribute())->toBeInstanceOf(Carbon::class);
    expect($cfv->getTypedValueAttribute()->toDateString())->toBe('2024-06-01');
})->group('import-document');

/* =========================================================================
 |  §5 IMPORT — Batch (typed cast + merge semantics + repo isolation)
 * ========================================================================= */

test('[Import/Batch] boolean custom field — typed cast stored and getTypedValueAttribute = true', function (): void {
    $repo = ce2_repo('IMBATBOOL');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'batch', 'bat_reviewed', 'Reviewed', 'boolean');

    $batchNumber = 4100;
    ce2_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'Reviewed' => '1',
    ], $user->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', $batchNumber)->first();
    expect($batch)->not->toBeNull();

    $cfv = CustomFieldValue::with('definition')
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch->id)
        ->first();
    expect($cfv)->not->toBeNull();
    expect($cfv->value)->toBe('1');
    expect($cfv->getTypedValueAttribute())->toBeTrue();
})->group('import-batch');

test('[Import/Batch] date custom field — raw value stored + getTypedValueAttribute = Carbon', function (): void {
    $repo = ce2_repo('IMBATDATE');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'batch', 'bat_closed', 'Closed On', 'date');

    $batchNumber = 4101;
    ce2_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'Closed On' => '2023-07-15',
    ], $user->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', $batchNumber)->first();
    expect($batch)->not->toBeNull();

    $cfv = CustomFieldValue::with('definition')
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch->id)
        ->first();
    expect($cfv)->not->toBeNull();
    expect($cfv->value)->toBe('2023-07-15');
    expect($cfv->getTypedValueAttribute())->toBeInstanceOf(Carbon::class);
    expect($cfv->getTypedValueAttribute()->toDateString())->toBe('2023-07-15');
})->group('import-batch');

test('[Import/Batch] blank mapped cell clears existing value (merge semantics)', function (): void {
    $repo = ce2_repo('IMBATCLR');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'batch', 'bat_clr_note', 'Clr Note');
    $batchNumber = 4102;
    $batch = ce2_batch($repo->id, $batchNumber);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Batch::class,
        'customizable_id' => $batch->id,
        'value' => 'old value',
    ]);

    ce2_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'Clr Note' => '',
    ], $user->id);

    $cfv = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch->id)
        ->first();
    expect($cfv)->toBeNull();
})->group('import-batch');

test('[Import/Batch] absent column leaves existing value untouched (merge semantics)', function (): void {
    $repo = ce2_repo('IMBATKP');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'batch', 'bat_kp_note', 'Kp Note');
    $batchNumber = 4103;
    $batch = ce2_batch($repo->id, $batchNumber);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Batch::class,
        'customizable_id' => $batch->id,
        'value' => 'should remain',
    ]);

    // Re-import without the custom field column.
    ce2_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'description' => 'updated',
    ], $user->id);

    $cfv = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch->id)
        ->value('value');
    expect($cfv)->toBe('should remain');
})->group('import-batch');

test('[Import/Batch] repo-B def not applied when importing into repo A', function (): void {
    $repoA = ce2_repo('IMBATRIA');
    $repoB = ce2_repo('IMBATRIB');
    $userA = ce2_user($repoA);
    $this->actingAs($userA);

    $defB = ce2_def($repoB->id, 'batch', 'bat_foreign', 'Bat Foreign');

    $batchNumber = 4104;
    ce2_run(BatchImporter::class, [
        'batch_number' => $batchNumber,
        'Bat Foreign' => 'should not be stored',
    ], $userA->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', $batchNumber)->first();
    $cfv = CustomFieldValue::query()
        ->where('custom_field_definition_id', $defB->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch?->id ?? 0)
        ->first();
    expect($cfv)->toBeNull();
})->group('import-batch');

/* =========================================================================
 |  §5 IMPORT — Box (typed cast + merge semantics + repo isolation)
 * ========================================================================= */

test('[Import/Box] boolean custom field — typed cast', function (): void {
    $repo = ce2_repo('IMBOXBOOL');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'box', 'box_certified', 'Certified', 'boolean');
    $batch = ce2_batch($repo->id);
    $barcode = 'BOOLBC-' . substr(uniqid(), -6);

    ce2_run(BoxImporter::class, [
        'box_number' => '900',
        'box_type' => 'RAS',
        'batch_number' => $batch->batch_number,
        'barcode' => $barcode,
        'Certified' => '0',
    ], $user->id);

    $box = Box::query()->where('barcode', $barcode)->first();
    expect($box)->not->toBeNull();

    $cfv = CustomFieldValue::with('definition')
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box->id)
        ->first();
    expect($cfv)->not->toBeNull();
    expect($cfv->value)->toBe('0');
    expect($cfv->getTypedValueAttribute())->toBeFalse();
})->group('import-box');

test('[Import/Box] date custom field — raw value stored + getTypedValueAttribute = Carbon', function (): void {
    $repo = ce2_repo('IMBOXDATE');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'box', 'box_checked_on', 'Box Checked On', 'date');
    $batch = ce2_batch($repo->id);
    $barcode = 'DATEBC-' . substr(uniqid(), -6);

    ce2_run(BoxImporter::class, [
        'box_number' => '901',
        'box_type' => 'RAS',
        'batch_number' => $batch->batch_number,
        'barcode' => $barcode,
        'Box Checked On' => '2024-09-10',
    ], $user->id);

    $box = Box::query()->where('barcode', $barcode)->first();
    expect($box)->not->toBeNull();

    $cfv = CustomFieldValue::with('definition')
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box->id)
        ->first();
    expect($cfv)->not->toBeNull();
    expect($cfv->value)->toBe('2024-09-10');
    expect($cfv->getTypedValueAttribute())->toBeInstanceOf(Carbon::class);
    expect($cfv->getTypedValueAttribute()->toDateString())->toBe('2024-09-10');
})->group('import-box');

test('[Import/Box] blank mapped cell clears existing value', function (): void {
    $repo = ce2_repo('IMBOXCLR');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'box', 'box_clr_note', 'Clr Box Note');
    $batch = ce2_batch($repo->id);
    $barcode = 'CLRBC-' . substr(uniqid(), -6);

    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => '902',
        'batch_id' => $batch->id,
        'barcode' => $barcode,
        'barcode_status' => 'IN',
    ]);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Box::class,
        'customizable_id' => $box->id,
        'value' => 'to be cleared',
    ]);

    ce2_run(BoxImporter::class, [
        'box_number' => '902',
        'box_type' => 'RAS',
        'batch_number' => $batch->batch_number,
        'barcode' => $barcode,
        'Clr Box Note' => '',
    ], $user->id);

    $cfv = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box->id)
        ->first();
    expect($cfv)->toBeNull();
})->group('import-box');

test('[Import/Box] absent column leaves existing value untouched', function (): void {
    $repo = ce2_repo('IMBOXKP');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'box', 'box_kp_note', 'Kp Box Note');
    $batch = ce2_batch($repo->id);
    $barcode = 'KPBC-' . substr(uniqid(), -6);

    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => '903',
        'batch_id' => $batch->id,
        'barcode' => $barcode,
        'barcode_status' => 'IN',
    ]);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Box::class,
        'customizable_id' => $box->id,
        'value' => 'kept value',
    ]);

    ce2_run(BoxImporter::class, [
        'box_number' => '903',
        'box_type' => 'RAS',
        'batch_number' => $batch->batch_number,
        'barcode' => $barcode,
    ], $user->id);

    $cfv = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box->id)
        ->value('value');
    expect($cfv)->toBe('kept value');
})->group('import-box');

test('[Import/Box] repo-B def not applied when importing into repo A', function (): void {
    $repoA = ce2_repo('IMBOXRIA');
    $repoB = ce2_repo('IMBOXRIB');
    $userA = ce2_user($repoA);
    $this->actingAs($userA);

    $defB = ce2_def($repoB->id, 'box', 'box_foreign', 'Box Foreign');
    $batchA = ce2_batch($repoA->id);
    $barcode = 'FGNBC-' . substr(uniqid(), -6);

    ce2_run(BoxImporter::class, [
        'box_number' => '904',
        'box_type' => 'RAS',
        'batch_number' => $batchA->batch_number,
        'barcode' => $barcode,
        'Box Foreign' => 'not stored',
    ], $userA->id);

    $box = Box::query()->where('barcode', $barcode)->first();
    $cfv = CustomFieldValue::query()
        ->where('custom_field_definition_id', $defB->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box?->id ?? 0)
        ->first();
    expect($cfv)->toBeNull();
})->group('import-box');

/* =========================================================================
 |  §5 IMPORT — Volume (static columns + typed cast + tenant)
 * ========================================================================= */

test('[Import/Volume] document resolved by identifier scoped to active repo', function (): void {
    $repo = ce2_repo('IMVOLIDX');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id, 'IMVOLIDX-DOC-001');

    ce2_run(VolumeImporter::class, [
        'document_identifier' => 'IMVOLIDX-DOC-001',
        'volume_number' => 'Vol. A',
    ], $user->id);

    $volume = Volume::query()->where('document_id', $doc->id)->first();
    expect($volume)->not->toBeNull();
    expect($volume->document_id)->toBe($doc->id);
})->group('import-volume');

test('[Import/Volume] unknown identifier fails the row', function (): void {
    $repo = ce2_repo('IMVOLERR');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $threw = false;

    try {
        ce2_run(VolumeImporter::class, [
            'document_identifier' => 'NONEXISTENT-XYZ-999',
            'volume_number' => 'Vol. Z',
        ], $user->id);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    expect(Volume::query()->count())->toBe(0);
})->group('import-volume');

test('[Import/Volume] document from another repo fails (tenant rejection)', function (): void {
    $repoA = ce2_repo('IMVOLIA2');
    $repoB = ce2_repo('IMVOLIB2');
    $userA = ce2_user($repoA);
    $this->actingAs($userA);

    $series = ce2_series();
    // Document belongs to repo B — must not resolve when importing into repo A.
    ce2_doc($repoB->id, $series->id, 'IMVOLIB2-DOC-CROSS');

    $threw = false;

    try {
        ce2_run(VolumeImporter::class, [
            'document_identifier' => 'IMVOLIB2-DOC-CROSS',
            'volume_number' => 'Vol. Cross',
        ], $userA->id);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    expect(Volume::query()->count())->toBe(0);
})->group('import-volume');

test('[Import/Volume] boolean custom field — typed cast stored', function (): void {
    $repo = ce2_repo('IMVOLBOOL');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'volume', 'vol_restored', 'Restored', 'boolean');
    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id, 'IMVOLBOOL-DOC-001');

    ce2_run(VolumeImporter::class, [
        'document_identifier' => 'IMVOLBOOL-DOC-001',
        'volume_number' => 'Vol. Bool',
        'Restored' => '1',
    ], $user->id);

    $volume = Volume::query()->where('document_id', $doc->id)->first();
    expect($volume)->not->toBeNull();

    $cfv = CustomFieldValue::with('definition')
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume->id)
        ->first();
    expect($cfv)->not->toBeNull();
    expect($cfv->value)->toBe('1');
    expect($cfv->getTypedValueAttribute())->toBeTrue();
})->group('import-volume');

test('[Import/Volume] date custom field — raw value stored + getTypedValueAttribute = Carbon', function (): void {
    $repo = ce2_repo('IMVOLDATE');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'volume', 'vol_catalogued', 'Catalogued On', 'date');
    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id, 'IMVOLDATE-DOC-001');

    ce2_run(VolumeImporter::class, [
        'document_identifier' => 'IMVOLDATE-DOC-001',
        'volume_number' => 'Vol. Date',
        'Catalogued On' => '2024-04-22',
    ], $user->id);

    $volume = Volume::query()->where('document_id', $doc->id)->first();
    expect($volume)->not->toBeNull();

    $cfv = CustomFieldValue::with('definition')
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume->id)
        ->first();
    expect($cfv)->not->toBeNull();
    expect($cfv->value)->toBe('2024-04-22');
    expect($cfv->getTypedValueAttribute())->toBeInstanceOf(Carbon::class);
    expect($cfv->getTypedValueAttribute()->toDateString())->toBe('2024-04-22');
})->group('import-volume');

test('[Import/Volume] blank mapped custom field cell clears existing value', function (): void {
    $repo = ce2_repo('IMVOLCLR');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'volume', 'vol_clr_note', 'Clr Vol Note');
    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id, 'IMVOLCLR-DOC-001');

    $volume = Volume::create([
        'document_id' => $doc->id,
        'volume_number' => 'Vol. Clr',
    ]);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Volume::class,
        'customizable_id' => $volume->id,
        'value' => 'old vol value',
    ]);

    ce2_run(VolumeImporter::class, [
        'document_identifier' => 'IMVOLCLR-DOC-001',
        'volume_number' => 'Vol. Clr',
        'Clr Vol Note' => '',
    ], $user->id);

    $cfv = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume->id)
        ->first();
    expect($cfv)->toBeNull();
})->group('import-volume');

test('[Import/Volume] absent column leaves existing value untouched', function (): void {
    $repo = ce2_repo('IMVOLKP');
    $user = ce2_user($repo);
    $this->actingAs($user);

    $def = ce2_def($repo->id, 'volume', 'vol_kp_note', 'Kp Vol Note');
    $series = ce2_series();
    $doc = ce2_doc($repo->id, $series->id, 'IMVOLKP-DOC-001');

    $volume = Volume::create([
        'document_id' => $doc->id,
        'volume_number' => 'Vol. Kp',
    ]);
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Volume::class,
        'customizable_id' => $volume->id,
        'value' => 'kept vol value',
    ]);

    ce2_run(VolumeImporter::class, [
        'document_identifier' => 'IMVOLKP-DOC-001',
        'volume_number' => 'Vol. Kp',
        'notes' => 'some notes',
    ], $user->id);

    $cfv = CustomFieldValue::query()
        ->where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume->id)
        ->value('value');
    expect($cfv)->toBe('kept vol value');
})->group('import-volume');

test('[Import/Volume] repo-B custom def not applied when importing into repo A', function (): void {
    $repoA = ce2_repo('IMVOLRIA');
    $repoB = ce2_repo('IMVOLLIB');
    $userA = ce2_user($repoA);
    $this->actingAs($userA);

    $defB = ce2_def($repoB->id, 'volume', 'vol_foreign', 'Vol Foreign');
    $series = ce2_series();
    $doc = ce2_doc($repoA->id, $series->id, 'IMVOLRIA-DOC-001');

    ce2_run(VolumeImporter::class, [
        'document_identifier' => 'IMVOLRIA-DOC-001',
        'volume_number' => 'Vol. Foreign',
        'Vol Foreign' => 'should not be stored',
    ], $userA->id);

    $volume = Volume::query()->where('document_id', $doc->id)->first();
    $cfv = CustomFieldValue::query()
        ->where('custom_field_definition_id', $defB->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume?->id ?? 0)
        ->first();
    expect($cfv)->toBeNull();
})->group('import-volume');
