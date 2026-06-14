<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Spec §Tests (View, table, export, import):
 *   Export: a Document custom field value appears in the CSV stream.
 *
 * Also smoke-tests that:
 *   - fixed columns still appear alongside custom-field columns
 *   - CSV header includes the definition label
 *   - rows without a stored value emit an empty cell (not missing)
 */
uses(RefreshDatabase::class);

/* -------------------------------------------------------------------------
 |  Local helpers
 * ------------------------------------------------------------------------- */

function cfe_user(string $role = 'super_admin', ?Repository $repo = null): User
{
    bl_seedShieldPermissions();
    $repo ??= Repository::factory()->create(['code' => 'CFE_' . substr(uniqid(), -6)]);
    $user = User::factory()->create([
        'email' => 'cfe-' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $user->assignRole($role);
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->refresh();

    return $user;
}

function cfe_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'CFE_' . substr(uniqid(), -4)],
        ['title' => 'CFE series', 'is_active' => true],
    );
}

function cfe_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'CFE-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

/**
 * Boot a properly-initialised ListDocuments Livewire component, call
 * exportToCsv() on it and return the raw CSV string (UTF-8 BOM stripped).
 */
function cfe_captureExportCsv(): string
{
    $component = Livewire::test(ListDocuments::class);
    /** @var ListDocuments $page */
    $page = $component->instance();

    ob_start();
    $page->exportToCsv()->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/* -------------------------------------------------------------------------
 |  Tests
 * ------------------------------------------------------------------------- */

test('custom field value appears in exported CSV', function (): void {
    // --- Arrange ---
    $repo = Repository::factory()->create(['code' => 'REPO_' . substr(uniqid(), -5)]);
    $user = cfe_user('super_admin', $repo);
    $series = cfe_series();
    $doc = cfe_doc($repo->id, $series->id);

    // Create a definition for 'document' entity type in this repository.
    /** @var CustomFieldDefinition $def */
    $def = CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'document',
        'key' => 'condition',
        'label' => 'Condition',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    // Store a value for the document.
    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => $doc->id,
        'value' => 'Excellent',
    ]);

    // --- Act ---
    $this->actingAs($user);
    $csv = cfe_captureExportCsv();

    // --- Assert ---
    // Header row must include both fixed columns and the custom-field label.
    expect($csv)->toContain('Identifier');
    expect($csv)->toContain('Condition');

    // The document row must contain the stored value.
    expect($csv)->toContain('Excellent');
});

test('document without a custom field value emits an empty cell', function (): void {
    $repo = Repository::factory()->create(['code' => 'REPO_' . substr(uniqid(), -5)]);
    $user = cfe_user('super_admin', $repo);
    $series = cfe_series();
    cfe_doc($repo->id, $series->id);

    // Definition exists but NO value stored for this document.
    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'document',
        'key' => 'shelf_note',
        'label' => 'Shelf note',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->actingAs($user);
    $csv = cfe_captureExportCsv();

    // Header must contain the label.
    expect($csv)->toContain('Shelf note');

    // Each row for documents without stored values must still have the correct
    // number of columns (the cell is empty, not missing).
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines), escape: '\\');
    $colCount = count($header);

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, escape: '\\');
        expect(count($cells))->toBe($colCount, "Row column count must match header: {$line}");
    }
});

test('no custom field columns in export when no definitions exist', function (): void {
    $repo = Repository::factory()->create(['code' => 'REPO_' . substr(uniqid(), -5)]);
    $user = cfe_user('super_admin', $repo);
    $series = cfe_series();
    cfe_doc($repo->id, $series->id);

    // No definitions — export should only contain fixed columns.
    $this->actingAs($user);
    $csv = cfe_captureExportCsv();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines), escape: '\\');

    // Fixed columns must be present.
    expect($header)->toContain('Identifier');
    expect($header)->toContain('Notes');

    // Should be exactly 11 fixed columns (per the fixed column map in exportToCsv).
    // Wave D4 — part_number added as the 9th column.
    // Wave F — number_of_acts (10th) and pages_folios (11th) added.
    expect(count($header))->toBe(11);
});
