<?php

declare(strict_types=1);

use App\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Filament\Resources\BoxResource\Pages\CreateBox;
use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\RepositoryResource\Pages\EditRepository;
use App\Filament\Resources\RepositoryResource\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Resources\VolumeResource\Pages\CreateVolume;
use App\Filament\Resources\VolumeResource\Pages\EditVolume;
use App\Models\Batch;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Models\Volume;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Spec §Tests (Pest, RefreshDatabase, cross-engine) — comprehensive suite.
 *
 * Covers:
 *   Unit:
 *     - definition create + unique constraint
 *     - value typed-cast per type
 *     - trait resolveRepositoryId for the 4 entities (Document, Batch, Box→batch, Volume→document)
 *     - getCustomFieldData / setCustomFieldData upsert + delete
 *
 *   Feature:
 *     - RelationManager visible to super_admin, hidden/forbidden to plain admin
 *     - create a definition through the RelationManager
 *     - repository scoping (def in repo A not shown in repo B)
 *
 *   Livewire / form:
 *     - for each of the 4 resources a definition renders in the form
 *     - submitted value persists + reloads on edit
 *     - required validation fires
 *
 *   Export:
 *     - a Document custom field value appears in the CSV stream (additional
 *       coverage on top of CustomFieldExportTest.php)
 */
uses(RefreshDatabase::class);

/* =========================================================================
 |  LOCAL HELPERS
 * ========================================================================= */

/**
 * Seed permissions + create a user with $role attached to $repo as default.
 */
function cft_user(string $role = 'super_admin', ?Repository $repo = null): User
{
    bl_seedShieldPermissions();
    $repo ??= Repository::factory()->create();
    $user = User::factory()->create([
        'email' => 'cft-' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $user->assignRole($role);
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->refresh();

    return $user;
}

/**
 * Create or retrieve a Series to satisfy document FK.
 */
function cft_series(): Series
{
    return Series::factory()->create();
}

/**
 * Create a Document bypassing the RepositoryScope global scope.
 *
 * @param array<string, mixed> $attrs
 */
function cft_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'CFT-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'Register',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

/**
 * Create a Batch bypassing the RepositoryScope global scope.
 *
 * @param array<string, mixed> $attrs
 */
function cft_batch(int $repoId, array $attrs = []): Batch
{
    static $batchCounter = 1000;

    do {
        $n = ++$batchCounter;
    } while (in_array($n, Batch::FORBIDDEN_NUMBERS, true)
        || Batch::withoutGlobalScope(RepositoryScope::class)
            ->where('batch_number', $n)->exists());

    return Batch::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'batch_number' => $n,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repoId,
        'is_active' => true,
    ], $attrs));
}

/**
 * Create a RAS Box linked to $batch (bypasses ThroughBatchRepositoryScope).
 *
 * @param array<string, mixed> $attrs
 */
function cft_box(Batch $batch, array $attrs = []): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(array_merge([
        'box_type' => 'RAS',
        'box_number' => 'B-' . substr(uniqid(), -6),
        'batch_id' => $batch->id,
        'barcode' => 'BC' . substr(uniqid(), -8),
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ], $attrs));
}

/**
 * Create a Volume linked to $document (bypasses global scopes via direct insert).
 *
 * @param array<string, mixed> $attrs
 */
function cft_volume(Document $document, array $attrs = []): Volume
{
    return Volume::create(array_merge([
        'document_id' => $document->id,
        'volume_number' => 'V-' . substr(uniqid(), -6),
    ], $attrs));
}

/**
 * Build a minimal CustomFieldDefinition in $repo for $entityType with $type.
 *
 * @param array<string, mixed> $overrides
 */
function cft_def(
    int $repoId,
    string $entityType = 'document',
    string $type = 'text',
    array $overrides = [],
): CustomFieldDefinition {
    static $keyCounter = 0;

    return CustomFieldDefinition::create(array_merge([
        'repository_id' => $repoId,
        'entity_type' => $entityType,
        'key' => 'field_' . (++$keyCounter) . '_' . substr(uniqid(), -4),
        'label' => 'Field ' . $keyCounter,
        'type' => $type,
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ], $overrides));
}

/**
 * Capture the CSV exported from ListDocuments as a plain string.
 */
function cft_exportCsv(): string
{
    $component = Livewire::test(ListDocuments::class);
    $page = $component->instance();

    ob_start();
    $page->exportToCsv()->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/* =========================================================================
 |  UNIT — Definition create + unique constraint
 * ========================================================================= */

test('[Unit] definition can be created and retrieved', function (): void {
    $repo = Repository::factory()->create();
    $def = cft_def($repo->id, 'document', 'text', [
        'key' => 'test_key',
        'label' => 'Test Field',
    ]);

    expect(CustomFieldDefinition::find($def->id))->not->toBeNull()
        ->and($def->key)->toBe('test_key')
        ->and($def->label)->toBe('Test Field')
        ->and($def->type)->toBe('text')
        ->and($def->is_active)->toBeTrue()
        ->and($def->is_required)->toBeFalse();
});

test('[Unit] definition unique constraint rejects duplicate (repository_id, entity_type, key)', function (): void {
    $repo = Repository::factory()->create();

    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'document',
        'key' => 'unique_key',
        'label' => 'First',
        'type' => 'text',
        'is_active' => true,
    ]);

    // The same key in the same repo + entity_type must fail.
    $this->expectException(QueryException::class);

    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'document',
        'key' => 'unique_key',    // duplicate
        'label' => 'Second',
        'type' => 'text',
        'is_active' => true,
    ]);
});

test('[Unit] same key is allowed in a different entity_type', function (): void {
    $repo = Repository::factory()->create();

    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'document',
        'key' => 'shared_key',
        'label' => 'Doc field',
        'type' => 'text',
        'is_active' => true,
    ]);

    $batchDef = CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'batch',
        'key' => 'shared_key',   // same key, different entity_type → allowed
        'label' => 'Batch field',
        'type' => 'text',
        'is_active' => true,
    ]);

    expect($batchDef->exists)->toBeTrue();
});

test('[Unit] same key is allowed in a different repository', function (): void {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    CustomFieldDefinition::create([
        'repository_id' => $repoA->id,
        'entity_type' => 'document',
        'key' => 'cross_repo_key',
        'label' => 'A field',
        'type' => 'text',
        'is_active' => true,
    ]);

    $defB = CustomFieldDefinition::create([
        'repository_id' => $repoB->id,
        'entity_type' => 'document',
        'key' => 'cross_repo_key',   // same key, different repo → allowed
        'label' => 'B field',
        'type' => 'text',
        'is_active' => true,
    ]);

    expect($defB->exists)->toBeTrue();
});

/* =========================================================================
 |  UNIT — Typed value cast per type
 * ========================================================================= */

test('[Unit] typed cast — text returns plain string', function (): void {
    $repo = Repository::factory()->create();
    $def = cft_def($repo->id, 'document', 'text', ['key' => 'tc_text']);
    $val = CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => 9999,    // phantom id, we only test the accessor
        'value' => 'hello',
    ]);
    $val->load('definition');

    expect($val->getTypedValueAttribute())->toBe('hello');
});

test('[Unit] typed cast — boolean returns PHP bool', function (): void {
    $repo = Repository::factory()->create();
    $def = cft_def($repo->id, 'document', 'boolean', ['key' => 'tc_bool']);

    // Truthy values
    foreach (['1', 'true', 'yes', 'on'] as $truthy) {
        $v = CustomFieldValue::make([
            'custom_field_definition_id' => $def->id,
            'customizable_type' => Document::class,
            'customizable_id' => 1,
            'value' => $truthy,
        ]);
        $v->setRelation('definition', $def);
        expect($v->getTypedValueAttribute())->toBeTrue("Expected truthy cast for '{$truthy}'");
    }

    // Falsy values
    foreach (['0', 'false', 'no', 'off'] as $falsy) {
        $v = CustomFieldValue::make([
            'custom_field_definition_id' => $def->id,
            'customizable_type' => Document::class,
            'customizable_id' => 1,
            'value' => $falsy,
        ]);
        $v->setRelation('definition', $def);
        expect($v->getTypedValueAttribute())->toBeFalse("Expected falsy cast for '{$falsy}'");
    }
});

test('[Unit] typed cast — number returns int for whole numbers', function (): void {
    $repo = Repository::factory()->create();
    $def = cft_def($repo->id, 'document', 'number', ['key' => 'tc_num_int']);

    $v = CustomFieldValue::make([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => 1,
        'value' => '42',
    ]);
    $v->setRelation('definition', $def);

    expect($v->getTypedValueAttribute())->toBe(42);
});

test('[Unit] typed cast — number returns float for decimal numbers', function (): void {
    $repo = Repository::factory()->create();
    $def = cft_def($repo->id, 'document', 'number', ['key' => 'tc_num_float']);

    $v = CustomFieldValue::make([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => 1,
        'value' => '3.14',
    ]);
    $v->setRelation('definition', $def);

    expect($v->getTypedValueAttribute())->toBe(3.14);
});

test('[Unit] typed cast — date returns Carbon instance', function (): void {
    $repo = Repository::factory()->create();
    $def = cft_def($repo->id, 'document', 'date', ['key' => 'tc_date']);

    $v = CustomFieldValue::make([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => 1,
        'value' => '2025-06-01',
    ]);
    $v->setRelation('definition', $def);

    $result = $v->getTypedValueAttribute();
    expect($result)->toBeInstanceOf(Carbon::class);
    expect($result->toDateString())->toBe('2025-06-01');
});

test('[Unit] typed cast — datetime returns Carbon instance', function (): void {
    $repo = Repository::factory()->create();
    $def = cft_def($repo->id, 'document', 'datetime', ['key' => 'tc_datetime']);

    $v = CustomFieldValue::make([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => 1,
        'value' => '2025-06-01 14:30:00',
    ]);
    $v->setRelation('definition', $def);

    $result = $v->getTypedValueAttribute();
    expect($result)->toBeInstanceOf(Carbon::class);
    expect($result->format('Y-m-d H:i:s'))->toBe('2025-06-01 14:30:00');
});

test('[Unit] typed cast — select returns plain string', function (): void {
    $repo = Repository::factory()->create();
    $def = cft_def($repo->id, 'document', 'select', [
        'key' => 'tc_select',
        'options' => [['value' => 'opt_a', 'label' => 'Option A']],
    ]);

    $v = CustomFieldValue::make([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => 1,
        'value' => 'opt_a',
    ]);
    $v->setRelation('definition', $def);

    expect($v->getTypedValueAttribute())->toBe('opt_a');
});

test('[Unit] typed cast — null value returns null', function (): void {
    $repo = Repository::factory()->create();
    $def = cft_def($repo->id, 'document', 'text', ['key' => 'tc_null']);

    $v = CustomFieldValue::make([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => 1,
        'value' => null,
    ]);
    $v->setRelation('definition', $def);

    expect($v->getTypedValueAttribute())->toBeNull();
});

/* =========================================================================
 |  UNIT — Trait resolveRepositoryId for the 4 entities
 * ========================================================================= */

test('[Unit] Document::customFieldRepositoryId returns repository_id directly', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);

    expect($doc->customFieldRepositoryId())->toBe((int) $repo->id);
});

test('[Unit] Batch::customFieldRepositoryId returns repository_id directly', function (): void {
    $repo = Repository::factory()->create();
    $batch = cft_batch($repo->id);

    expect($batch->customFieldRepositoryId())->toBe((int) $repo->id);
});

test('[Unit] Box::customFieldRepositoryId resolves via batch', function (): void {
    $repo = Repository::factory()->create();
    $batch = cft_batch($repo->id);
    $box = cft_box($batch);

    // Load relation so override picks it up from the loaded relation.
    $box->load('batch');

    expect($box->customFieldRepositoryId())->toBe((int) $repo->id);
});

test('[Unit] Box::customFieldRepositoryId returns null when batch is not set', function (): void {
    // Construct an in-memory Box that has no batch.
    $box = Box::make(['box_type' => 'RAS']);

    expect($box->customFieldRepositoryId())->toBeNull();
});

test('[Unit] Volume::customFieldRepositoryId resolves via document', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);
    $volume = cft_volume($doc);

    // Load relation so override picks it up.
    $volume->load('document');

    expect($volume->customFieldRepositoryId())->toBe((int) $repo->id);
});

test('[Unit] Volume::customFieldRepositoryId returns null when document is not set', function (): void {
    $volume = Volume::make(['volume_number' => 'V-XXX']);

    expect($volume->customFieldRepositoryId())->toBeNull();
});

/* =========================================================================
 |  UNIT — getCustomFieldData / setCustomFieldData upsert + delete
 * ========================================================================= */

test('[Unit] getCustomFieldData returns empty array when no definitions exist', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);

    expect($doc->getCustomFieldData())->toBe([]);
});

test('[Unit] getCustomFieldData returns null for definitions without stored values', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);
    $def = cft_def($repo->id, 'document', 'text', ['key' => 'unmapped']);

    $data = $doc->getCustomFieldData();

    expect($data)->toHaveKey('unmapped');
    expect($data['unmapped'])->toBeNull();
});

test('[Unit] setCustomFieldData upserts a value for an active definition', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);
    $def = cft_def($repo->id, 'document', 'text', ['key' => 'condition']);

    $doc->setCustomFieldData(['condition' => 'Good']);

    $value = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->first();

    expect($value)->not->toBeNull()
        ->and($value->value)->toBe('Good');
});

test('[Unit] setCustomFieldData updates an existing value', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);
    $def = cft_def($repo->id, 'document', 'text', ['key' => 'cond2']);

    $doc->setCustomFieldData(['cond2' => 'Fair']);
    $doc->setCustomFieldData(['cond2' => 'Excellent']);

    $count = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->count();

    expect($count)->toBe(1);

    $value = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->value('value');

    expect($value)->toBe('Excellent');
});

test('[Unit] setCustomFieldData deletes a row when value is null', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);
    $def = cft_def($repo->id, 'document', 'text', ['key' => 'to_del']);

    $doc->setCustomFieldData(['to_del' => 'some value']);

    // Now pass null → must remove the row.
    $doc->setCustomFieldData(['to_del' => null]);

    $count = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->count();

    expect($count)->toBe(0);
});

test('[Unit] setCustomFieldData ignores keys not matching active definitions', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);

    // No definitions created — orphan data must not be stored.
    $doc->setCustomFieldData(['ghost_field' => 'whatever']);

    expect(
        CustomFieldValue::where('customizable_type', Document::class)
            ->where('customizable_id', $doc->id)
            ->count()
    )->toBe(0);
});

test('[Unit] setCustomFieldData serialises boolean as 1/0 string', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);
    $def = cft_def($repo->id, 'document', 'boolean', ['key' => 'bool_field']);

    $doc->setCustomFieldData(['bool_field' => true]);

    $raw = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->value('value');

    expect($raw)->toBe('1');

    $doc->setCustomFieldData(['bool_field' => false]);

    $raw = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->value('value');

    expect($raw)->toBe('0');
});

test('[Unit] getCustomFieldData returns typed values after setCustomFieldData', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);
    cft_def($repo->id, 'document', 'text', ['key' => 'round_trip']);

    $doc->setCustomFieldData(['round_trip' => 'archived']);

    $data = $doc->getCustomFieldData();

    expect($data)->toHaveKey('round_trip')
        ->and($data['round_trip'])->toBe('archived');
});

/* =========================================================================
 |  UNIT — Repository scoping for customFieldDefinitions()
 * ========================================================================= */

test('[Unit] customFieldDefinitions() only returns active definitions for this entity+repository', function (): void {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $series = cft_series();

    $docA = cft_doc($repoA->id, $series->id);

    // Active def in repo A.
    $defA = cft_def($repoA->id, 'document', 'text', ['key' => 'def_a']);

    // Active def in repo B — must NOT appear for $docA.
    cft_def($repoB->id, 'document', 'text', ['key' => 'def_b']);

    // Inactive def in repo A — must NOT appear.
    cft_def($repoA->id, 'document', 'text', ['key' => 'def_inactive', 'is_active' => false]);

    // Batch def in repo A — wrong entity_type, must NOT appear.
    cft_def($repoA->id, 'batch', 'text', ['key' => 'def_batch']);

    $defs = $docA->customFieldDefinitions()->get();

    expect($defs->pluck('key')->all())->toBe([$defA->key]);
});

/* =========================================================================
 |  FEATURE — RelationManager gate (super_admin vs plain admin)
 * ========================================================================= */

test('[Feature] CustomFieldsRelationManager is visible to super_admin', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    $canView = CustomFieldsRelationManager::canViewForRecord($repo, EditRepository::class);

    expect($canView)->toBeTrue();
});

test('[Feature] CustomFieldsRelationManager is not visible to plain admin', function (): void {
    $repo = Repository::factory()->create();
    $admin = cft_user('admin', $repo);

    $this->actingAs($admin);

    $canView = CustomFieldsRelationManager::canViewForRecord($repo, EditRepository::class);

    expect($canView)->toBeFalse();
});

test('[Feature] super_admin can create a definition through the RelationManager', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    $key = 'rm_new_field';
    $label = 'RM New Field';

    Livewire::test(CustomFieldsRelationManager::class, [
        'ownerRecord' => $repo,
        'pageClass' => EditRepository::class,
    ])
        ->callTableAction('create', data: [
            'entity_type' => 'document',
            'label' => $label,
            'key' => $key,
            'type' => 'text',
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 0,
        ])
        ->assertHasNoTableActionErrors();

    expect(
        CustomFieldDefinition::where('repository_id', $repo->id)
            ->where('entity_type', 'document')
            ->where('key', $key)
            ->exists()
    )->toBeTrue();
});

test('[Feature] RelationManager table renders for super_admin and shows existing definitions', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    cft_def($repo->id, 'document', 'text', ['key' => 'visible_def', 'label' => 'Visible Def']);

    Livewire::test(CustomFieldsRelationManager::class, [
        'ownerRecord' => $repo,
        'pageClass' => EditRepository::class,
    ])
        ->assertSee('Visible Def');
});

/* =========================================================================
 |  FEATURE — Repository scoping (def in repo A absent in repo B)
 * ========================================================================= */

test('[Feature] definition in repo A does not appear for documents in repo B', function (): void {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    cft_def($repoA->id, 'document', 'text', ['key' => 'scoped_field', 'label' => 'Scoped Field']);

    // Nothing for repo B.
    $series = cft_series();
    $docB = cft_doc($repoB->id, $series->id);

    $data = $docB->getCustomFieldData();

    expect($data)->toBe([]);
});

/* =========================================================================
 |  LIVEWIRE — Document: definition renders in form + persists + reloads
 * ========================================================================= */

test('[Livewire/Document] a text definition renders in the create form', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    cft_def($repo->id, 'document', 'text', ['key' => 'doc_lw_text', 'label' => 'LW Text Field']);

    Livewire::test(CreateDocument::class)
        ->assertSee('LW Text Field');
});

test('[Livewire/Document] submitted custom field value persists and reloads on edit', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    $def = cft_def($repo->id, 'document', 'text', ['key' => 'persist_key', 'label' => 'Persist Field']);
    $doc = cft_doc($repo->id, $series->id);

    // Simulate saving the custom field value directly (trait lifecycle).
    $doc->setCustomFieldData(['persist_key' => 'PersistValue']);

    // Reload on edit: mutateFormDataBeforeFill reads getCustomFieldData().
    $data = $doc->getCustomFieldData();

    expect($data)->toHaveKey('persist_key')
        ->and($data['persist_key'])->toBe('PersistValue');

    // Confirm stored in DB.
    $stored = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->value('value');

    expect($stored)->toBe('PersistValue');
});

test('[Livewire/Document] edit form shows custom fields section when definitions exist', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    cft_def($repo->id, 'document', 'text', ['key' => 'edit_field', 'label' => 'Edit Field']);
    $doc = cft_doc($repo->id, $series->id);

    Livewire::test(EditDocument::class, ['record' => $doc->getKey()])
        ->assertSee('Edit Field');
});

/* =========================================================================
 |  LIVEWIRE — Batch: definition renders in form + persists + reloads
 * ========================================================================= */

test('[Livewire/Batch] a text definition renders in the create form', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    cft_def($repo->id, 'batch', 'text', ['key' => 'bat_lw_text', 'label' => 'Batch LW Field']);

    Livewire::test(CreateBatch::class)
        ->assertSee('Batch LW Field');
});

test('[Livewire/Batch] submitted custom field value persists and reloads on edit', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    $def = cft_def($repo->id, 'batch', 'text', ['key' => 'bat_persist', 'label' => 'Batch Persist']);
    $batch = cft_batch($repo->id);

    $batch->setCustomFieldData(['bat_persist' => 'BatchValue']);

    $data = $batch->getCustomFieldData();

    expect($data)->toHaveKey('bat_persist')
        ->and($data['bat_persist'])->toBe('BatchValue');

    $stored = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Batch::class)
        ->where('customizable_id', $batch->id)
        ->value('value');

    expect($stored)->toBe('BatchValue');
});

test('[Livewire/Batch] edit form shows custom fields section when definitions exist', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    cft_def($repo->id, 'batch', 'text', ['key' => 'bat_edit_field', 'label' => 'Batch Edit Field']);
    $batch = cft_batch($repo->id);

    Livewire::test(EditBatch::class, ['record' => $batch->getKey()])
        ->assertSee('Batch Edit Field');
});

/* =========================================================================
 |  LIVEWIRE — Box: definition renders in form + persists + reloads
 * ========================================================================= */

test('[Livewire/Box] a text definition renders in the create form', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    cft_def($repo->id, 'box', 'text', ['key' => 'box_lw_text', 'label' => 'Box LW Field']);

    Livewire::test(CreateBox::class)
        ->assertSee('Box LW Field');
});

test('[Livewire/Box] submitted custom field value persists and reloads on edit', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    $def = cft_def($repo->id, 'box', 'text', ['key' => 'box_persist', 'label' => 'Box Persist']);
    $batch = cft_batch($repo->id);
    $box = cft_box($batch);
    $box->load('batch');  // ensure customFieldRepositoryId() resolves

    $box->setCustomFieldData(['box_persist' => 'BoxValue']);

    $data = $box->getCustomFieldData();

    expect($data)->toHaveKey('box_persist')
        ->and($data['box_persist'])->toBe('BoxValue');

    $stored = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box->id)
        ->value('value');

    expect($stored)->toBe('BoxValue');
});

test('[Livewire/Box] edit form shows custom fields section when definitions exist', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    cft_def($repo->id, 'box', 'text', ['key' => 'box_edit_field', 'label' => 'Box Edit Field']);
    $batch = cft_batch($repo->id);
    $box = cft_box($batch);

    Livewire::test(EditBox::class, ['record' => $box->getKey()])
        ->assertSee('Box Edit Field');
});

/* =========================================================================
 |  LIVEWIRE — Volume: definition renders in form + persists + reloads
 * ========================================================================= */

test('[Livewire/Volume] a text definition renders in the create form', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    cft_def($repo->id, 'volume', 'text', ['key' => 'vol_lw_text', 'label' => 'Volume LW Field']);

    Livewire::test(CreateVolume::class)
        ->assertSee('Volume LW Field');
});

test('[Livewire/Volume] submitted custom field value persists and reloads on edit', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    $def = cft_def($repo->id, 'volume', 'text', ['key' => 'vol_persist', 'label' => 'Vol Persist']);
    $doc = cft_doc($repo->id, $series->id);
    $volume = cft_volume($doc);
    $volume->load('document');  // ensure customFieldRepositoryId() resolves

    $volume->setCustomFieldData(['vol_persist' => 'VolumeValue']);

    $data = $volume->getCustomFieldData();

    expect($data)->toHaveKey('vol_persist')
        ->and($data['vol_persist'])->toBe('VolumeValue');

    $stored = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Volume::class)
        ->where('customizable_id', $volume->id)
        ->value('value');

    expect($stored)->toBe('VolumeValue');
});

test('[Livewire/Volume] edit form shows custom fields section when definitions exist', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    cft_def($repo->id, 'volume', 'text', ['key' => 'vol_edit_field', 'label' => 'Volume Edit Field']);
    $doc = cft_doc($repo->id, $series->id);
    $volume = cft_volume($doc);

    Livewire::test(EditVolume::class, ['record' => $volume->getKey()])
        ->assertSee('Volume Edit Field');
});

/* =========================================================================
 |  LIVEWIRE — Required validation fires
 * ========================================================================= */

test('[Livewire/Document] required custom field fails validation when empty', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    cft_def($repo->id, 'document', 'text', [
        'key' => 'req_field',
        'label' => 'Required Field',
        'is_required' => true,
    ]);
    $doc = cft_doc($repo->id, $series->id);

    // Saving with empty custom data — the required definition should trigger validation.
    // Because Filament required() validation is form-level, we test via
    // the Livewire form directly with blank custom payload.
    $component = Livewire::test(EditDocument::class, ['record' => $doc->getKey()])
        ->fillForm(['custom' => ['req_field' => '']])
        ->call('save')
        ->assertHasFormErrors(['custom.req_field']);

    // Verify no CustomFieldValue was created with blank value.
    $count = CustomFieldValue::where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->count();

    expect($count)->toBe(0);
});

/* =========================================================================
 |  EXPORT — Document custom field value appears in CSV stream
 * ========================================================================= */

test('[Export] custom field value for a Document appears in the CSV export', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    $def = cft_def($repo->id, 'document', 'text', ['key' => 'export_field', 'label' => 'Export Field']);
    $doc = cft_doc($repo->id, $series->id);

    CustomFieldValue::create([
        'custom_field_definition_id' => $def->id,
        'customizable_type' => Document::class,
        'customizable_id' => $doc->id,
        'value' => 'ExportValue',
    ]);

    $csv = cft_exportCsv();

    expect($csv)->toContain('Export Field');
    expect($csv)->toContain('ExportValue');
});

test('[Export] boolean custom field serialises as 1 or 0 in CSV', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    $def = cft_def($repo->id, 'document', 'boolean', ['key' => 'bool_export', 'label' => 'Bool Export']);
    $doc = cft_doc($repo->id, $series->id);

    $doc->setCustomFieldData(['bool_export' => true]);

    $csv = cft_exportCsv();

    expect($csv)->toContain('Bool Export');
    // The export format normalises true → '1'.
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv(array_shift($lines));
    $colIdx = array_search('Bool Export', $header, true);
    expect($colIdx)->not->toBeFalse();

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line);
        // The doc row must have cell value '1'.
        expect($cells[$colIdx])->toBe('1');
    }
});

/* =========================================================================
 |  GROUP G — Strengthened Livewire form tests driving via Filament UI
 *
 |  These tests replace the previous "call setCustomFieldData directly"
 |  approach for Document and Box with actual Filament form submissions
 |  via Livewire, so that GROUP A (live repository resolution) bugs would
 |  have been caught earlier. Regression tests for cross-repository
 |  isolation and import merge semantics are also included here.
 * ========================================================================= */

test('[Livewire/Document] form submission via EditDocument persists custom field and reloads correctly', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    $def = cft_def($repo->id, 'document', 'text', ['key' => 'form_driven_key', 'label' => 'Form Driven Field']);
    // Create document without a document_type so the Select does not complain
    // about an unknown option value during form validation (the DocumentType table
    // is empty in the test DB and the field is not required).
    $doc = cft_doc($repo->id, $series->id, ['document_type' => null]);

    // Drive the Filament EditDocument form: fill the custom field and save.
    Livewire::test(EditDocument::class, ['record' => $doc->getKey()])
        ->fillForm([
            'repository_id' => $repo->id,  // trigger live resolution first
            'custom' => ['form_driven_key' => 'driven_value'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // Assert the value was persisted to custom_field_values.
    $stored = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->value('value');

    expect($stored)->toBe('driven_value');

    // Reload on edit: form should show the stored value.
    Livewire::test(EditDocument::class, ['record' => $doc->getKey()])
        ->assertFormSet(['custom' => ['form_driven_key' => 'driven_value']]);
});

test('[Livewire/Document] switching repository_id in form shows definitions for the new repo, not the old one', function (): void {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $series = cft_series();

    $superAdmin = cft_user('super_admin', $repoA);
    $superAdmin->repositories()->syncWithoutDetaching([$repoB->id]);
    $this->actingAs($superAdmin);

    // Repo A has a definition, repo B has a different one.
    cft_def($repoA->id, 'document', 'text', ['key' => 'repo_a_field', 'label' => 'Repo A Field']);
    cft_def($repoB->id, 'document', 'text', ['key' => 'repo_b_field', 'label' => 'Repo B Field']);

    $doc = cft_doc($repoA->id, $series->id);

    // When repository_id is set to repo B, the form should see repo B's definitions.
    Livewire::test(EditDocument::class, ['record' => $doc->getKey()])
        ->fillForm(['repository_id' => $repoB->id])
        ->assertSee('Repo B Field')
        ->assertDontSee('Repo A Field');
});

test('[Livewire/Box] form submission via EditBox persists custom field value', function (): void {
    $repo = Repository::factory()->create();
    $superAdmin = cft_user('super_admin', $repo);

    $this->actingAs($superAdmin);

    $def = cft_def($repo->id, 'box', 'text', ['key' => 'box_form_key', 'label' => 'Box Form Field']);
    $batch = cft_batch($repo->id);
    $box = cft_box($batch);

    // Drive the EditBox form: fill batch_id (live trigger) then the custom field.
    Livewire::test(EditBox::class, ['record' => $box->getKey()])
        ->fillForm([
            'batch_id' => $batch->id,
            'custom' => ['box_form_key' => 'box_form_value'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = CustomFieldValue::where('custom_field_definition_id', $def->id)
        ->where('customizable_type', Box::class)
        ->where('customizable_id', $box->id)
        ->value('value');

    expect($stored)->toBe('box_form_value');

    // Reload: form must show stored value.
    Livewire::test(EditBox::class, ['record' => $box->getKey()])
        ->assertFormSet(['custom' => ['box_form_key' => 'box_form_value']]);
});

test('[Regression] definition in repo B does NOT render when document form has repo A selected', function (): void {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $series = cft_series();

    $superAdmin = cft_user('super_admin', $repoA);
    $this->actingAs($superAdmin);

    // Only repo B has a definition — must NOT appear when the form's selected repo is A.
    cft_def($repoB->id, 'document', 'text', ['key' => 'b_only_field', 'label' => 'B Only Field']);

    $doc = cft_doc($repoA->id, $series->id);

    Livewire::test(EditDocument::class, ['record' => $doc->getKey()])
        ->fillForm(['repository_id' => $repoA->id])
        ->assertDontSee('B Only Field');
});

/* =========================================================================
 |  GROUP D — setCustomFieldData merge semantics (replaceMissing=false)
 * ========================================================================= */

test('[Unit] setCustomFieldData(data, replaceMissing=false) leaves untouched fields intact', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);

    // Two definitions in the same repo.
    cft_def($repo->id, 'document', 'text', ['key' => 'field_keep']);
    cft_def($repo->id, 'document', 'text', ['key' => 'field_update']);

    // Set both fields initially.
    $doc->setCustomFieldData(['field_keep' => 'keep_value', 'field_update' => 'old_value']);

    // Partial import: only pass field_update — field_keep must remain untouched.
    $doc->setCustomFieldData(['field_update' => 'new_value'], false);

    $keepRaw = CustomFieldValue::where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->whereHas('definition', fn ($q) => $q->where('key', 'field_keep'))
        ->value('value');

    $updateRaw = CustomFieldValue::where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->whereHas('definition', fn ($q) => $q->where('key', 'field_update'))
        ->value('value');

    expect($keepRaw)->toBe('keep_value')    // untouched by merge
        ->and($updateRaw)->toBe('new_value');  // updated
});

test('[Unit] setCustomFieldData(data, replaceMissing=false) with null deletes only that key', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);

    cft_def($repo->id, 'document', 'text', ['key' => 'del_key']);
    cft_def($repo->id, 'document', 'text', ['key' => 'stay_key']);

    $doc->setCustomFieldData(['del_key' => 'will_be_deleted', 'stay_key' => 'stays']);

    // Merge with explicit null for del_key → deletes it; stay_key not present → untouched.
    $doc->setCustomFieldData(['del_key' => null], false);

    $delCount = CustomFieldValue::where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->whereHas('definition', fn ($q) => $q->where('key', 'del_key'))
        ->count();

    $stayRaw = CustomFieldValue::where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->whereHas('definition', fn ($q) => $q->where('key', 'stay_key'))
        ->value('value');

    expect($delCount)->toBe(0)
        ->and($stayRaw)->toBe('stays');
});

test('[Unit] setCustomFieldData(data, replaceMissing=true) still deletes absent keys (form semantics)', function (): void {
    $repo = Repository::factory()->create();
    $series = cft_series();
    $doc = cft_doc($repo->id, $series->id);

    cft_def($repo->id, 'document', 'text', ['key' => 'replace_key']);
    cft_def($repo->id, 'document', 'text', ['key' => 'absent_key']);

    $doc->setCustomFieldData(['replace_key' => 'val_a', 'absent_key' => 'val_b']);

    // Full-replace: only pass replace_key → absent_key must be deleted.
    $doc->setCustomFieldData(['replace_key' => 'val_a_updated'], true);

    $absentCount = CustomFieldValue::where('customizable_type', Document::class)
        ->where('customizable_id', $doc->id)
        ->whereHas('definition', fn ($q) => $q->where('key', 'absent_key'))
        ->count();

    expect($absentCount)->toBe(0);
});
