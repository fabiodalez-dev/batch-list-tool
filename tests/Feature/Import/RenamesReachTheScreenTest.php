<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource;
use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Filament\Resources\AuthorityResource;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Filament\Resources\BatchResource;
use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Filament\Resources\BoxResource;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentTypeResource;
use App\Filament\Resources\DocumentTypeResource\Pages\ListDocumentTypes;
use App\Filament\Resources\LocationResource;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Filament\Resources\SeriesResource;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Filament\Resources\VolumeResource;
use App\Filament\Resources\VolumeResource\Pages\ListVolumes;
use App\Models\ColumnLabelOverride;
use App\Models\Repository;
use App\Models\User;
use App\Support\ColumnLabels\ColumnLabels;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * Client, 2026-09-28: "although the template columns are correct, the headers
 * in the page are different than the one in the template. Case in point Surname
 * and Given Name which are Creator Surname and Creator Name in the template."
 * Then: "I tried to change it from the Repository but it doesn't change."
 *
 * She was right, and renaming was half-built. Four Authority columns — the
 * identifiers — read their label from ColumnLabels. Every other column on every
 * screen had its label written by hand, so a rename reached the template and
 * the importer and stopped at the edge of the interface.
 *
 * That is worse than not offering the rename at all: she renamed a column, the
 * app told her nothing had gone wrong, and the screen kept the old name.
 *
 * These tests walk the real form, record page and table of every resource and
 * assert that any field a repository is allowed to rename shows the name that
 * repository gave it. They are deliberately exhaustive rather than a handful of
 * examples — the defect was a missing case, and a sample of cases cannot catch
 * a missing one.
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

/**
 * Each renameable entity, its Filament resource and the list page used to build
 * a Livewire context for the schemas.
 *
 * @return array<string, array{0: class-string, 1: class-string}>
 */
function rts_resources(): array
{
    return [
        'authority' => [AuthorityResource::class, ListAuthorities::class],
        'series' => [SeriesResource::class, ListSeries::class],
        'batch' => [BatchResource::class, ListBatches::class],
        'box' => [BoxResource::class, ListBoxes::class],
        'location' => [LocationResource::class, ListLocations::class],
        'documentType' => [DocumentTypeResource::class, ListDocumentTypes::class],
        'document' => [DocumentResource::class, ListDocuments::class],
        'volume' => [VolumeResource::class, ListVolumes::class],
        'accession' => [AccessionResource::class, ListAccessions::class],
    ];
}

function rts_admin(Repository $repo): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'rts+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Every label a resource puts on screen for a renameable field, as
 * "context.field" => label. Contexts: form, view (the record page), table.
 *
 * @return array<string, string>
 */
function rts_screenLabels(string $entity): array
{
    [$resource, $listPage] = rts_resources()[$entity];
    $renameable = ColumnLabels::DEFAULTS[$entity];
    $livewire = Livewire::test($listPage)->instance();

    // A Repeater bound to a relationship resolves that relationship while the
    // schema is being walked, and blows up on a null model — so the schemas get
    // a model to stand on. Nothing here saves; it only makes the walk possible
    // on the two resources (Box, Document) that have repeaters.
    $schema = fn (): Schema => Schema::make($livewire)->model($resource::getModel());

    $labels = [];

    foreach ($resource::form($schema())->getFlatComponents(withHidden: true) as $component) {
        if ($component instanceof Field && array_key_exists($component->getName(), $renameable)) {
            $labels['form.' . $component->getName()] = (string) $component->getLabel();
        }
    }

    if (method_exists($resource, 'infolist')) {
        foreach ($resource::infolist($schema())->getFlatComponents(withHidden: true) as $component) {
            if ($component instanceof Entry && array_key_exists($component->getName(), $renameable)) {
                $labels['view.' . $component->getName()] = (string) $component->getLabel();
            }
        }
    }

    foreach ($resource::table(Table::make($livewire))->getColumns() as $column) {
        if (array_key_exists($column->getName(), $renameable)) {
            $labels['table.' . $column->getName()] = (string) $column->getLabel();
        }
    }

    return $labels;
}

it('shows the factory name on screen when nothing has been renamed', function (string $entity): void {
    $repo = Repository::factory()->create(['code' => 'RRS' . substr(md5($entity), 0, 5)]);
    $this->actingAs(rts_admin($repo));

    // The label on screen has to agree with the template header BEFORE any
    // rename too. This is the half she noticed first: the template said
    // "Creator Surname" and the table said "Surname", with nothing renamed
    // at all — two names for one column, and no way to tell which is right.
    $wrong = [];
    foreach (rts_screenLabels($entity) as $where => $label) {
        $field = substr($where, strpos($where, '.') + 1);
        $expected = ColumnLabels::DEFAULTS[$entity][$field];
        if ($label !== $expected) {
            $wrong[] = "{$entity}.{$where}: shows '{$label}', template says '{$expected}'";
        }
    }

    expect($wrong)->toBe([]);
})->with(array_keys(rts_resources()));

it('carries a rename onto the form, the record page and the table', function (string $entity): void {
    $repo = Repository::factory()->create(['code' => 'RRR' . substr(md5($entity), 0, 5)]);
    $this->actingAs(rts_admin($repo));

    // Rename every renameable field of this entity at once, then require the
    // interface to use the new name everywhere it shows that field.
    $renamed = [];
    foreach (ColumnLabels::DEFAULTS[$entity] as $field => $factory) {
        $renamed[$field] = 'Renamed ' . $field;
        ColumnLabelOverride::create([
            'repository_id' => $repo->id,
            'entity_type' => $entity,
            'field_key' => $field,
            'label' => $renamed[$field],
        ]);
    }
    ColumnLabels::flushMemo();

    $stale = [];
    foreach (rts_screenLabels($entity) as $where => $label) {
        $field = substr($where, strpos($where, '.') + 1);
        if ($label !== $renamed[$field]) {
            $stale[] = "{$entity}.{$where}: still '{$label}', renamed to '{$renamed[$field]}'";
        }
    }

    expect($stale)->toBe([]);
})->with(array_keys(rts_resources()));

it('renames Creator Surname and Creator Name on the authorities table', function (): void {
    $repo = Repository::factory()->create(['code' => 'RRSCH']);
    $this->actingAs(rts_admin($repo));

    // Her exact case, kept as its own test so the regression has a name.
    $labels = rts_screenLabels('authority');

    expect($labels['table.surname'] ?? null)->toBe('Creator Surname')
        ->and($labels['table.given_names'] ?? null)->toBe('Creator Name')
        ->and($labels['table.entity_type'] ?? null)->toBe('Type of Entity');
});
