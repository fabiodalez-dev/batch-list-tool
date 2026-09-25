<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource;
use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Filament\Resources\DocumentTypeResource;
use App\Filament\Resources\DocumentTypeResource\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypeResource\Pages\EditDocumentType;
use App\Filament\Resources\DocumentTypeResource\Pages\ListDocumentTypes;
use App\Filament\Resources\LocationResource;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Filament\Resources\SeriesResource;
use App\Filament\Resources\SeriesResource\Pages\CreateSeries;
use App\Filament\Resources\SeriesResource\Pages\EditSeries;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Models\CustomFieldDefinition;
use App\Models\DocumentType;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\ColumnLabels\ColumnLabels;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Forms\Components\Field;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * The other half of the standalone-columns work: a column she can define but
 * cannot fill in is not a feature. These cover the form side — the field being
 * rendered, and the value surviving a save.
 *
 * The persistence itself comes from HandlesCustomFieldForm, which is new on
 * these four page classes. It is a trait, and a trait loses to a method of the
 * same name declared on the class, silently — so an end-to-end save is the only
 * thing that actually proves it is wired.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    ColumnLabels::flushMemo();
    CustomFieldResolver::flush();
});

afterEach(function (): void {
    ColumnLabels::flushMemo();
    CustomFieldResolver::flush();
});

function scf_admin(Repository $repo): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'scf+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function scf_define(Repository $repo, string $entity, string $key, string $label): CustomFieldDefinition
{
    CustomFieldResolver::flush();

    return CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => $entity,
        'key' => $key,
        'label' => $label,
        'type' => 'text',
        'is_active' => true,
        'sort_order' => 1,
    ]);
}

/** Every field in a resource's form, keyed by name. */
function scf_formFields(string $resource, string $listPage): array
{
    $schema = Schema::make(Livewire::test($listPage)->instance());

    $fields = [];
    foreach ($resource::form($schema)->getFlatComponents(withHidden: true) as $component) {
        if ($component instanceof Field) {
            $fields[$component->getName()] = $component->getLabel();
        }
    }

    return $fields;
}

it('renders the added column on the subseries form', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCF1']);
    $this->actingAs(scf_admin($repo));
    scf_define($repo, 'series', 'legacy_shelf', 'Legacy Shelf');

    expect(scf_formFields(SeriesResource::class, ListSeries::class))
        ->toHaveKey('custom.legacy_shelf');
});

it('renders the added column on the location form', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCF2']);
    $this->actingAs(scf_admin($repo));
    scf_define($repo, 'location', 'floor', 'Floor');

    expect(scf_formFields(LocationResource::class, ListLocations::class))
        ->toHaveKey('custom.floor');
});

it('renders the added column on the document type form', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCF3']);
    $this->actingAs(scf_admin($repo));
    scf_define($repo, 'documentType', 'retention', 'Retention');

    expect(scf_formFields(DocumentTypeResource::class, ListDocumentTypes::class))
        ->toHaveKey('custom.retention');
});

it('renders the added column on the notary accession form', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCF4']);
    $this->actingAs(scf_admin($repo));
    scf_define($repo, 'accession', 'donor', 'Donor');

    expect(scf_formFields(AccessionResource::class, ListAccessions::class))
        ->toHaveKey('custom.donor');
});

it('hides the section entirely when this repository has added nothing', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCF5']);
    $this->actingAs(scf_admin($repo));

    // No definitions: the operator should not be shown an empty "Custom
    // fields" panel on every record page.
    expect(scf_formFields(SeriesResource::class, ListSeries::class))
        ->not->toHaveKey('custom.legacy_shelf');
});

it('saves an added column from the document type create form', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCF6']);
    $this->actingAs(scf_admin($repo));
    scf_define($repo, 'documentType', 'retention', 'Retention');

    Livewire::test(CreateDocumentType::class)
        ->fillForm([
            'identifier' => 'DT00970',
            'name' => 'Form-created Type',
            'is_active' => true,
            'custom' => ['retention' => 'Permanent'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $type = DocumentType::where('identifier', 'DT00970')->first();

    expect($type)->not->toBeNull()
        ->and($type->getCustomFieldData())->toBe(['retention' => 'Permanent']);
});

it('saves an added column from the document type edit form', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCF7']);
    $this->actingAs(scf_admin($repo));
    scf_define($repo, 'documentType', 'retention', 'Retention');

    $type = DocumentType::create(['identifier' => 'DT00971', 'name' => 'Editable', 'is_active' => true]);
    $type->setCustomFieldData(['retention' => 'Ten years']);

    Livewire::test(EditDocumentType::class, ['record' => $type->getKey()])
        // The stored value must reach the form, or the operator silently
        // overwrites it with a blank on the next save.
        ->assertFormSet(['custom.retention' => 'Ten years'])
        ->fillForm(['custom' => ['retention' => 'Permanent']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($type->fresh()->getCustomFieldData())->toBe(['retention' => 'Permanent']);
});

it('saves an added column from the subseries create form', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCF8']);
    $this->actingAs(scf_admin($repo));
    scf_define($repo, 'series', 'legacy_shelf', 'Legacy Shelf');

    Livewire::test(CreateSeries::class)
        ->fillForm([
            'code' => 'SCF-FORM',
            'title' => 'Form-created Subseries',
            'repository_id' => $repo->id,
            'is_active' => true,
            'custom' => ['legacy_shelf' => 'Shelf 3'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $series = Series::where('code', 'SCF-FORM')->first();

    expect($series)->not->toBeNull()
        ->and($series->getCustomFieldData())->toBe(['legacy_shelf' => 'Shelf 3']);
});

it('clears an added column when the operator blanks it', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCF9']);
    $this->actingAs(scf_admin($repo));
    scf_define($repo, 'series', 'legacy_shelf', 'Legacy Shelf');

    $series = Series::factory()->create(['code' => 'SCF-BLANK', 'repository_id' => $repo->id]);
    $series->setCustomFieldData(['legacy_shelf' => 'Shelf 9']);

    // The form submits every field, so an empty one genuinely means "cleared" —
    // unlike an import, where an absent column means "not mentioned".
    Livewire::test(EditSeries::class, ['record' => $series->getKey()])
        ->fillForm(['custom' => ['legacy_shelf' => null]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($series->fresh()->getCustomFieldData())->toBe(['legacy_shelf' => null]);
});
