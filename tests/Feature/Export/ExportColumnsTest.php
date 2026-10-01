<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Batch;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\FieldPermissionOverride;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\FieldPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * What the export puts in its columns beyond the template itself: added
 * columns in every repository mode, and nothing a user may not read.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function ec_csv(string $page): array
{
    $component = Livewire::test($page)->callAction('export_csv');
    $csv = (string) base64_decode((string) $component->effects['download']['content'], true);
    $csv = preg_replace('/^\x{FEFF}/u', '', $csv) ?? $csv;

    return array_map(static fn (string $l): array => str_getcsv($l, escape: '\\'), array_values(array_filter(explode("\n", $csv))));
}

it('keeps the added columns, with their values, when "All repositories" is selected', function (): void {
    $repo = Repository::factory()->create(['code' => 'ECA']);
    // No default repository: the "All repositories" view.
    $admin = User::factory()->create(['is_active' => true, 'default_repository_id' => null]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '9911', 'type' => 'MAIN_COLLECTION', 'repository_id' => $repo->id, 'is_active' => true]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => 'E1', 'batch_id' => $batch->id, 'barcode' => 'EC000001', 'barcode_status' => 'IN', 'is_legacy' => false]);
    $definition = CustomFieldDefinition::create(['repository_id' => $repo->id, 'entity_type' => 'box', 'key' => 'shelf_colour', 'label' => 'Shelf colour', 'type' => 'text', 'is_active' => true, 'sort_order' => 1]);
    CustomFieldValue::create(['custom_field_definition_id' => $definition->id, 'customizable_type' => $box->getMorphClass(), 'customizable_id' => $box->id, 'value' => 'blue']);

    [$header, $row] = ec_csv(ListBoxes::class);

    // Before: the column vanished in this mode, without a word.
    expect($header)->toContain('Shelf colour')
        ->and($row[array_search('Shelf colour', $header, true)])->toBe('blue');
});

it('leaves a column out of the file when the user may not read it — derived columns included', function (): void {
    $repo = Repository::factory()->create(['code' => 'ECP']);
    $series = Series::create(['code' => 'ECPS', 'title' => 'S', 'is_active' => true, 'repository_id' => $repo->id]);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '9921', 'type' => 'MAIN_COLLECTION', 'repository_id' => $repo->id, 'is_active' => true]);
    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'ECP-1', 'repository_id' => $repo->id, 'series_id' => $series->id, 'batch_id' => $batch->id,
        'document_type' => 'T', 'notes' => 'private note',
    ]);

    // notes is a plain column; the batch is the derived "RAS Batch 1", stored
    // in batch_id — hiding batch_id must take the derived column with it.
    FieldPermissionOverride::create(['resource' => 'document', 'field' => 'notes', 'hidden_from' => ['editor']]);
    FieldPermissionOverride::create(['resource' => 'document', 'field' => 'batch_id', 'hidden_from' => ['editor']]);
    FieldPermissions::flushCache();

    $editor = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $editor->assignRole('editor');
    $editor->repositories()->attach($repo->id);
    $this->actingAs($editor);

    $rows = ec_csv(ListDocuments::class);
    $header = $rows[0];

    expect($header)->not->toContain('Note')
        ->and($header)->not->toContain('RAS Batch 1')
        ->and($header)->toContain('Document Identifier')
        ->and(implode(',', $rows[1]))->not->toContain('private note')
        ->and(implode(',', $rows[1]))->not->toContain('9921');

    // The same file for an administrator still carries both.
    $admin = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    expect(ec_csv(ListDocuments::class)[0])->toContain('Note')->toContain('RAS Batch 1');
});
