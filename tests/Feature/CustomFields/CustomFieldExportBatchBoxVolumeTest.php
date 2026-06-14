<?php

declare(strict_types=1);

use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Spec §3 (export) — Batch, Box, Volume export tests.
 *
 * For each entity:
 *   - active custom-field columns appear in the CSV header
 *   - per-row typed values are formatted correctly
 *   - rows without a stored value emit an empty cell (not missing column)
 *   - no custom-field columns when no definitions exist
 *   - boolean → 1/0, date → Y-m-d
 *   - definitions from a different repository are absent
 */
uses(RefreshDatabase::class);

/* =========================================================================
 |  Shared helpers
 * ========================================================================= */

function cfx_roles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

/**
 * Create a super_admin user attached to a repository as their default.
 */
function cfx_user(Repository $repo): User
{
    bl_seedShieldPermissions();
    cfx_roles();
    $user = User::factory()->create([
        'email' => 'cfx-' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $user->assignRole('super_admin');
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->refresh();

    return $user;
}

function cfx_repo(string $prefix = 'CFX'): Repository
{
    return Repository::factory()->create(['code' => $prefix . '_' . substr(uniqid(), -6)]);
}

function cfx_batch(int $repoId): Batch
{
    do {
        $n = random_int(2000, 8999);
    } while (in_array($n, [33, 34, 36], true)
        || Batch::withoutGlobalScope(RepositoryScope::class)
            ->where('batch_number', $n)->exists());

    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function cfx_box(int $batchId, array $attrs = []): Box
{
    return Box::create(array_merge([
        'box_type' => 'RAS',
        'box_number' => 'BOX-' . strtoupper(substr(uniqid(), -6)),
        'batch_id' => $batchId,
        'barcode' => 'BC-' . strtoupper(substr(uniqid(), -6)),
        'barcode_status' => 'IN',
    ], $attrs));
}

function cfx_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'CFX_' . substr(uniqid(), -4)],
        ['title' => 'CFX series', 'is_active' => true],
    );
}

function cfx_document(int $repoId, int $seriesId): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'CFX-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ]);
}

function cfx_volume(int $documentId, array $attrs = []): Volume
{
    return Volume::create(array_merge([
        'document_id' => $documentId,
        'volume_number' => 'VOL-' . strtoupper(substr(uniqid(), -6)),
    ], $attrs));
}

/**
 * Boot a properly-initialised ListBatches Livewire component, call
 * exportToCsv() on it and return the raw CSV string (UTF-8 BOM stripped).
 */
function cfx_batchCsv(): string
{
    $component = Livewire::test(ListBatches::class);
    /** @var ListBatches $page */
    $page = $component->instance();

    ob_start();
    $page->exportToCsv()->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/**
 * Boot a properly-initialised ListBoxes Livewire component and return CSV.
 */
function cfx_boxCsv(): string
{
    $component = Livewire::test(ListBoxes::class);
    /** @var ListBoxes $page */
    $page = $component->instance();

    ob_start();
    $page->exportToCsv()->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/**
 * Boot a properly-initialised ListVolumes Livewire component and return CSV.
 */
function cfx_volumeCsv(): string
{
    $component = Livewire::test(ListVolumes::class);
    /** @var ListVolumes $page */
    $page = $component->instance();

    ob_start();
    $page->exportToCsv()->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/* =========================================================================
 |  BATCH export tests
 * ========================================================================= */

test('Batch export: custom field value appears in CSV', function (): void {
    $repo = cfx_repo('BCHEXP');
    $user = cfx_user($repo);
    $batch = cfx_batch($repo->id);

    $def = CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'batch',
        'key' => 'provenance',
        'label' => 'Provenance',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Batch::class,
        'customizable_id' => $batch->id,
        'value' => 'Malta National Archives',
    ]);

    $this->actingAs($user);
    $csv = cfx_batchCsv();

    // Header must include the custom field label.
    expect($csv)->toContain('Provenance');
    // Fixed columns still present.
    expect($csv)->toContain('Batch number');
    // Value row must contain the stored text.
    expect($csv)->toContain('Malta National Archives');
});

test('Batch export: boolean custom field renders as 1 or 0', function (): void {
    $repo = cfx_repo('BCHBOOL');
    $user = cfx_user($repo);
    $batch = cfx_batch($repo->id);

    $def = CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'batch',
        'key' => 'is_sealed',
        'label' => 'Is sealed?',
        'type' => 'boolean',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Batch::class,
        'customizable_id' => $batch->id,
        'value' => '1',
    ]);

    $this->actingAs($user);
    $csv = cfx_batchCsv();

    expect($csv)->toContain('Is sealed?');
    // Boolean true → '1'
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines), escape: '\\');
    $colIdx = array_search('Is sealed?', $header, true);
    expect($colIdx)->not->toBeFalse();

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, escape: '\\');
        // Value must be '1' for the boolean true case.
        expect($cells[$colIdx])->toBe('1');
    }
});

test('Batch export: no custom field columns when no definitions exist', function (): void {
    $repo = cfx_repo('BCHNODEF');
    $user = cfx_user($repo);
    cfx_batch($repo->id);

    $this->actingAs($user);
    $csv = cfx_batchCsv();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines), escape: '\\');

    // Fixed columns present (A4 Wave A added Repository as a 5th fixed column).
    expect($header)->toContain('Batch number');
    expect($header)->toContain('Type');
    expect($header)->toContain('Description');
    expect($header)->toContain('Repository');
    expect($header)->toContain('Is active?');

    // Exactly 5 fixed columns (Batch number, Type, Description, Repository, Is active?), no extras.
    expect(count($header))->toBe(5);
});

test('Batch export: row without custom field value emits empty cell', function (): void {
    $repo = cfx_repo('BCHEMPTY');
    $user = cfx_user($repo);
    cfx_batch($repo->id);

    // Definition exists but no value stored.
    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'batch',
        'key' => 'origin',
        'label' => 'Origin',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($user);
    $csv = cfx_batchCsv();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines), escape: '\\');
    $colCount = count($header);
    expect($header)->toContain('Origin');

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, escape: '\\');
        expect(count($cells))->toBe($colCount, "Column count mismatch: {$line}");
    }
});

test('Batch export: definition from another repository is absent', function (): void {
    $repoA = cfx_repo('BCHA');
    $repoB = cfx_repo('BCHB');
    $userA = cfx_user($repoA);
    cfx_batch($repoA->id);

    // Definition in repo B — must not appear in repo A's export.
    CustomFieldDefinition::create([
        'repository_id' => $repoB->id,
        'entity_type' => 'batch',
        'key' => 'other_field',
        'label' => 'Should Not Appear',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($userA);
    $csv = cfx_batchCsv();

    expect($csv)->not->toContain('Should Not Appear');
});

/* =========================================================================
 |  BOX export tests
 * ========================================================================= */

test('Box export: custom field value appears in CSV', function (): void {
    $repo = cfx_repo('BOXEXP');
    $user = cfx_user($repo);
    $batch = cfx_batch($repo->id);
    $box = cfx_box($batch->id);

    $def = CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'box',
        'key' => 'condition',
        'label' => 'Condition',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Box::class,
        'customizable_id' => $box->id,
        'value' => 'Good',
    ]);

    $this->actingAs($user);
    $csv = cfx_boxCsv();

    expect($csv)->toContain('Condition');
    expect($csv)->toContain('Box number');
    expect($csv)->toContain('Good');
});

test('Box export: date custom field renders as Y-m-d', function (): void {
    $repo = cfx_repo('BOXDATE');
    $user = cfx_user($repo);
    $batch = cfx_batch($repo->id);
    $box = cfx_box($batch->id);

    $def = CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'box',
        'key' => 'last_inspected',
        'label' => 'Last inspected',
        'type' => 'date',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Box::class,
        'customizable_id' => $box->id,
        'value' => '2024-03-15',
    ]);

    $this->actingAs($user);
    $csv = cfx_boxCsv();

    expect($csv)->toContain('Last inspected');
    expect($csv)->toContain('2024-03-15');
});

test('Box export: no custom field columns when no definitions exist', function (): void {
    $repo = cfx_repo('BOXNODEF');
    $user = cfx_user($repo);
    $batch = cfx_batch($repo->id);
    cfx_box($batch->id);

    $this->actingAs($user);
    $csv = cfx_boxCsv();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines), escape: '\\');

    expect($header)->toContain('Box number');
    expect($header)->toContain('Batch number');
    expect($header)->toContain('Barcode');
    // 8 fixed columns, no extras.
    expect(count($header))->toBe(8);
});

test('Box export: row without custom field value emits empty cell', function (): void {
    $repo = cfx_repo('BOXEMPTY');
    $user = cfx_user($repo);
    $batch = cfx_batch($repo->id);
    cfx_box($batch->id);

    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'box',
        'key' => 'shelf',
        'label' => 'Shelf location',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($user);
    $csv = cfx_boxCsv();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines), escape: '\\');
    $colCount = count($header);
    expect($header)->toContain('Shelf location');

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, escape: '\\');
        expect(count($cells))->toBe($colCount, "Column count mismatch: {$line}");
    }
});

test('Box export: definition from another repository is absent', function (): void {
    $repoA = cfx_repo('BOXA');
    $repoB = cfx_repo('BOXB');
    $userA = cfx_user($repoA);
    $batchA = cfx_batch($repoA->id);
    cfx_box($batchA->id);

    CustomFieldDefinition::create([
        'repository_id' => $repoB->id,
        'entity_type' => 'box',
        'key' => 'foreign_field',
        'label' => 'Should Not Appear',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($userA);
    $csv = cfx_boxCsv();

    expect($csv)->not->toContain('Should Not Appear');
});

/* =========================================================================
 |  VOLUME export tests
 * ========================================================================= */

test('Volume export: custom field value appears in CSV', function (): void {
    $repo = cfx_repo('VOLEXP');
    $user = cfx_user($repo);
    $series = cfx_series();
    $doc = cfx_document($repo->id, $series->id);
    $volume = cfx_volume($doc->id);

    $def = CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'volume',
        'key' => 'language',
        'label' => 'Language',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Volume::class,
        'customizable_id' => $volume->id,
        'value' => 'Maltese',
    ]);

    $this->actingAs($user);
    $csv = cfx_volumeCsv();

    // Header must include both fixed columns and the custom-field label.
    expect($csv)->toContain('Document identifier');
    expect($csv)->toContain('Volume number');
    expect($csv)->toContain('Language');
    // Value row must contain the stored text.
    expect($csv)->toContain('Maltese');
});

test('Volume export: fixed columns include document identifier', function (): void {
    $repo = cfx_repo('VOLIDX');
    $user = cfx_user($repo);
    $series = cfx_series();
    $doc = cfx_document($repo->id, $series->id);
    cfx_volume($doc->id);

    $this->actingAs($user);
    $csv = cfx_volumeCsv();

    // The document's identifier must appear in the data row.
    expect($csv)->toContain($doc->identifier);
});

test('Volume export: boolean custom field renders as 1 or 0', function (): void {
    $repo = cfx_repo('VOLBOOL');
    $user = cfx_user($repo);
    $series = cfx_series();
    $doc = cfx_document($repo->id, $series->id);
    $volume = cfx_volume($doc->id);

    $def = CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'volume',
        'key' => 'digitised',
        'label' => 'Digitised?',
        'type' => 'boolean',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Volume::class,
        'customizable_id' => $volume->id,
        'value' => '0',
    ]);

    $this->actingAs($user);
    $csv = cfx_volumeCsv();

    expect($csv)->toContain('Digitised?');

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines), escape: '\\');
    $colIdx = array_search('Digitised?', $header, true);
    expect($colIdx)->not->toBeFalse();

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, escape: '\\');
        // Boolean false → '0'
        expect($cells[$colIdx])->toBe('0');
    }
});

test('Volume export: no custom field columns when no definitions exist', function (): void {
    $repo = cfx_repo('VOLNODEF');
    $user = cfx_user($repo);
    $series = cfx_series();
    $doc = cfx_document($repo->id, $series->id);
    cfx_volume($doc->id);

    $this->actingAs($user);
    $csv = cfx_volumeCsv();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines), escape: '\\');

    // Exactly 5 fixed columns.
    expect($header)->toContain('Document identifier');
    expect($header)->toContain('Volume number');
    expect($header)->toContain('Dates start');
    expect($header)->toContain('Dates end');
    expect($header)->toContain('Notes');
    expect(count($header))->toBe(5);
});

test('Volume export: row without custom field value emits empty cell', function (): void {
    $repo = cfx_repo('VOLEMPTY');
    $user = cfx_user($repo);
    $series = cfx_series();
    $doc = cfx_document($repo->id, $series->id);
    cfx_volume($doc->id);

    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'volume',
        'key' => 'catalogue_note',
        'label' => 'Catalogue note',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($user);
    $csv = cfx_volumeCsv();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines), escape: '\\');
    $colCount = count($header);
    expect($header)->toContain('Catalogue note');

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, escape: '\\');
        expect(count($cells))->toBe($colCount, "Column count mismatch: {$line}");
    }
});

test('Volume export: definition from another repository is absent', function (): void {
    $repoA = cfx_repo('VOLA');
    $repoB = cfx_repo('VOLB');
    $userA = cfx_user($repoA);
    $series = cfx_series();
    $doc = cfx_document($repoA->id, $series->id);
    cfx_volume($doc->id);

    CustomFieldDefinition::create([
        'repository_id' => $repoB->id,
        'entity_type' => 'volume',
        'key' => 'foreign',
        'label' => 'Should Not Appear',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($userA);
    $csv = cfx_volumeCsv();

    expect($csv)->not->toContain('Should Not Appear');
});
