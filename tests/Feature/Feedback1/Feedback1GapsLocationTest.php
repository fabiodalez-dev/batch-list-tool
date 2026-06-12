<?php

declare(strict_types=1);

use App\Filament\Imports\LocationImporter;
use App\Filament\Resources\LocationResource;
use App\Filament\Support\CreatorColumn;
use App\Models\Location;
use App\Models\LocationType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\AuditableObserver;

/**
 * Feedback1 gaps — GROUP LOC:
 *  1. Location Types as an editable DB-backed lookup (Room / Museum /
 *     Repository seeded by the create_location_types migration).
 *  2. LocationResource `type` Select options sourced from the lookup.
 *  3. Creator (Inputter) tracked on Locations via the audit-based
 *     CreatorColumn, same mechanism as BatchResource.
 *  4. Legacy rows storing the lowercase codes ('room', …) stay valid.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    // F007 — typeLabel() memoises the lookup map per request; flush it so
    // LocationType rows created in one case don't bleed into the next.
    LocationResource::flushTypeLabelMemo();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function f1loc_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'F1L-' . strtoupper(substr(uniqid(), -6)),
    ]);
}

function f1loc_location(array $attrs = []): Location
{
    return Location::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'name' => 'Loc-F1L-' . substr(uniqid(), -6),
        'type' => 'room',
        'repository_id' => f1loc_repo()->id,
        'is_active' => true,
    ], $attrs));
}

// ===========================================================================
// 1. Migration seed — three canonical rows
// ===========================================================================

it('LOC-Seed.1: migration seeds exactly room, museum, repository', function (): void {
    $codes = LocationType::query()->orderBy('sort_order')->pluck('code')->all();

    expect($codes)->toBe(['room', 'museum', 'repository']);
});

it('LOC-Seed.2: seeded rows carry the human labels and are active', function (): void {
    expect(LocationType::query()->where('code', 'room')->value('label'))->toBe('Room')
        ->and(LocationType::query()->where('code', 'museum')->value('label'))->toBe('Museum')
        ->and(LocationType::query()->where('code', 'repository')->value('label'))->toBe('Repository')
        ->and(LocationType::query()->where('is_active', true)->count())->toBe(3);
});

// ===========================================================================
// 2. Lookup CRUD basics (model level)
// ===========================================================================

it('LOC-Crud.1: a new location type can be created', function (): void {
    $type = LocationType::create([
        'code' => 'vault',
        'label' => 'Vault',
        'sort_order' => 10,
        'is_active' => true,
    ]);

    expect($type->exists)->toBeTrue()
        ->and(LocationType::query()->where('code', 'vault')->exists())->toBeTrue();
});

it('LOC-Crud.2: a location type label can be edited', function (): void {
    $type = LocationType::query()->where('code', 'museum')->firstOrFail();
    $type->update(['label' => 'Museum Hall']);

    expect($type->fresh()->label)->toBe('Museum Hall');
});

it('LOC-Crud.3: a location type can be deleted', function (): void {
    $type = LocationType::create(['code' => 'tmp', 'label' => 'Temp']);
    $type->delete();

    expect(LocationType::query()->where('code', 'tmp')->exists())->toBeFalse();
});

it('LOC-Crud.4: deactivated types are excluded from options()', function (): void {
    LocationType::query()->where('code', 'museum')->update(['is_active' => false]);

    $options = LocationType::options();

    expect($options)->not->toHaveKey('museum')
        ->and($options)->toHaveKeys(['room', 'repository']);
});

it('LOC-Crud.5: options() respects sort_order (lower numbers first)', function (): void {
    LocationType::query()->where('code', 'repository')->update(['sort_order' => 0]);
    LocationType::query()->where('code', 'room')->update(['sort_order' => 5]);
    LocationType::query()->where('code', 'museum')->update(['sort_order' => 9]);

    expect(array_keys(LocationType::options()))->toBe(['repository', 'room', 'museum']);
});

it('LOC-Crud.6: optionsWith() keeps an inactive current value selectable', function (): void {
    LocationType::query()->where('code', 'museum')->update(['is_active' => false]);

    $options = LocationType::optionsWith('museum');

    expect($options)->toHaveKey('museum')
        ->and($options['museum'])->toContain('(inactive)');
});

// ===========================================================================
// 3. LocationResource type options come from the lookup
// ===========================================================================

it('LOC-Res.1: typeOptions() returns the seeded lookup values keyed by code', function (): void {
    expect(LocationResource::typeOptions())->toBe([
        'room' => 'Room',
        'museum' => 'Museum',
        'repository' => 'Repository',
    ]);
});

it('LOC-Res.2: a newly added active lookup row appears in typeOptions()', function (): void {
    LocationType::create([
        'code' => 'vault',
        'label' => 'Vault',
        'sort_order' => 99,
        'is_active' => true,
    ]);

    expect(LocationResource::typeOptions())->toHaveKey('vault')
        ->and(LocationResource::typeOptions()['vault'])->toBe('Vault');
});

it('LOC-Res.3: a deactivated lookup row disappears from typeOptions()', function (): void {
    LocationType::query()->where('code', 'museum')->update(['is_active' => false]);

    expect(LocationResource::typeOptions())->not->toHaveKey('museum');
});

it('LOC-Res.4: typeOptions() falls back to CANONICAL_TYPES when the table is empty', function (): void {
    LocationType::query()->delete();

    expect(LocationResource::typeOptions())->toBe(Location::CANONICAL_TYPES);
});

it('LOC-Res.5: typeOptionsWith() keeps an inactive current type selectable (C4)', function (): void {
    LocationType::query()->where('code', 'museum')->update(['is_active' => false]);

    $options = LocationResource::typeOptionsWith('museum');

    expect($options)->toHaveKey('museum')
        ->and($options['museum'])->toContain('(inactive)')
        ->and($options)->toHaveKeys(['room', 'repository']);
});

it('LOC-Res.6: typeOptionsWith() keeps a pre-lookup legacy type (shelf) selectable', function (): void {
    $options = LocationResource::typeOptionsWith('shelf');

    expect($options)->toHaveKey('shelf')
        ->and($options['shelf'])->toBe('Shelf')
        ->and($options)->toHaveKeys(['room', 'museum', 'repository']);
});

// ===========================================================================
// 4. Creator tracked on Locations
// ===========================================================================

it('LOC-Creator.1: creating a Location records the creator in the audit trail', function (): void {
    // Owen-it's observer does not attach in CLI/test mode by default — flip
    // the config and re-observe so the pipeline runs exactly as over HTTP.
    config(['audit.console' => true]);
    Location::observe(AuditableObserver::class);

    $creator = User::factory()->create(['name' => 'Loc Creator']);
    $creator->assignRole('super_admin'); // bypass multi-tenant check
    $this->actingAs($creator);

    $location = f1loc_location();

    $audit = $location->audits()->where('event', 'created')->oldest('id')->first();

    expect($audit)->not->toBeNull()
        ->and((int) $audit->getAttribute('user_id'))->toBe($creator->id);
});

it('LOC-Creator.2: CreatorColumn resolves the creator name for a Location row', function (): void {
    config(['audit.console' => true]);
    Location::observe(AuditableObserver::class);

    $creator = User::factory()->create(['name' => 'Bob Locator']);
    $creator->assignRole('super_admin');
    $this->actingAs($creator);

    $location = f1loc_location();

    $closure = CreatorColumn::make()->getGetStateUsingCallback();

    expect($closure($location))->toBe('Bob Locator');
});

// ===========================================================================
// 5. Legacy compatibility — stored lowercase codes stay valid
// ===========================================================================

it('LOC-Legacy.1: legacy rows with type=room are still valid and re-saveable', function (): void {
    $location = f1loc_location(['type' => 'room']);

    expect($location->fresh()->type)->toBe('room');

    // Re-saving an untouched legacy row must not blank or rewrite the type.
    $location->update(['name' => 'Renamed legacy room']);

    expect($location->fresh()->type)->toBe('room');
});

it('LOC-Legacy.2: pre-lookup legacy types (e.g. shelf) remain storable', function (): void {
    $location = f1loc_location(['type' => 'shelf']);

    expect($location->fresh()->type)->toBe('shelf');
});

// ===========================================================================
// 6. F007 — typeLabel() honours the editable lookup label
// ===========================================================================

it('LOC-Label.1: typeLabel() returns the admin-configured lookup label for a custom code', function (): void {
    LocationType::create([
        'code' => 'cold_store',
        'label' => 'Cold Storage',
        'sort_order' => 99,
        'is_active' => true,
    ]);
    LocationResource::flushTypeLabelMemo();

    expect(LocationResource::typeLabel('cold_store'))->toBe('Cold Storage');
});

it('LOC-Label.2: typeLabel() returns the lookup label for a canonical code (room => Room)', function (): void {
    expect(LocationResource::typeLabel('room'))->toBe('Room');
});

it('LOC-Label.3: typeLabel() falls back to the hardcoded match for an unknown code', function (): void {
    // No lookup row for 'shelf' (not seeded), so the match() arm provides it.
    expect(LocationResource::typeLabel('shelf'))->toBe('Shelf');
});

// ===========================================================================
// 7. F006 — the table type SelectFilter is lookup-driven
// ===========================================================================

it('LOC-Filter.1: SelectFilter options include a lookup-added code (via typeOptions)', function (): void {
    LocationType::create([
        'code' => 'vault',
        'label' => 'Vault',
        'sort_order' => 99,
        'is_active' => true,
    ]);

    // The filter sources its options from self::typeOptions() (same closure),
    // so asserting on typeOptions() proves the operator-added code is filterable.
    expect(LocationResource::typeOptions())
        ->toHaveKey('vault')
        ->and(LocationResource::typeOptions()['vault'])->toBe('Vault');
});

// ===========================================================================
// 8. F038 — LocationImporter accepts lookup-added type codes
// ===========================================================================

it('LOC-Import.1: the type column accepts a lookup-added code', function (): void {
    LocationType::create([
        'code' => 'vault',
        'label' => 'Vault',
        'sort_order' => 99,
        'is_active' => true,
    ]);

    $col = collect(LocationImporter::getColumns())
        ->first(fn ($c) => $c->getName() === 'type');

    // Cast keeps the lookup-added code instead of nulling it.
    expect($col->castState('vault'))->toBe('vault');

    // The in: rule lists the lookup code so validation passes.
    $inRule = collect($col->getDataValidationRules())
        ->first(fn ($r) => is_string($r) && str_starts_with($r, 'in:'));
    expect($inRule)->toContain('vault');
});

it('LOC-Import.2: the type column still accepts canonical codes and aliases', function (): void {
    $col = collect(LocationImporter::getColumns())
        ->first(fn ($c) => $c->getName() === 'type');

    // Canonical const code.
    expect($col->castState('room'))->toBe('room')
        // Alias map runs before the membership check.
        ->and($col->castState('display case'))->toBe('showcase');

    $inRule = collect($col->getDataValidationRules())
        ->first(fn ($r) => is_string($r) && str_starts_with($r, 'in:'));
    expect($inRule)->toContain('room')
        ->and($inRule)->toContain('showcase');
});
