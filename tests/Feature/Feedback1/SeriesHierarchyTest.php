<?php

declare(strict_types=1);

use App\Filament\Resources\SeriesResource\Pages\CreateSeries;
use App\Filament\Resources\SeriesResource\Pages\EditSeries;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 Wave C1.4 — hierarchical, multi-level Series. Drives the real
 * Filament pages and exercises the cycle-prevention.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function sh_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'sh-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function sh_series(string $code, ?int $parentId = null): Series
{
    return Series::create([
        'code' => $code . '_' . substr(uniqid(), -4),
        'title' => $code . ' title',
        'parent_id' => $parentId,
        'is_active' => true,
    ]);
}

it('lets a series have a parent through the create form', function () {
    $this->actingAs(sh_actAsSuperAdmin());
    $parent = sh_series('R');

    Livewire::test(CreateSeries::class)
        ->fillForm([
            'code' => 'REG_' . substr(uniqid(), -4),
            'title' => 'Registers',
            'parent_id' => $parent->id,
            'is_wills_series' => false,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Series::where('parent_id', $parent->id)->exists())->toBeTrue();
});

it('supports multi-level hierarchy (grandparent → parent → child)', function () {
    $this->actingAs(sh_actAsSuperAdmin());

    $grandparent = sh_series('R');
    $parent = sh_series('REG', $grandparent->id);
    $child = sh_series('RWL', $parent->id);

    expect($child->parent->id)->toBe($parent->id)
        ->and($parent->parent->id)->toBe($grandparent->id);

    // Ancestor chain is root-first.
    $ancestorIds = array_map(fn (Series $s) => $s->id, $child->ancestors());
    expect($ancestorIds)->toBe([$grandparent->id, $parent->id]);

    // Grandparent sees both descendants.
    $descendantIds = $grandparent->descendants()->pluck('id')->sort()->values()->all();
    expect($descendantIds)->toBe(collect([$parent->id, $child->id])->sort()->values()->all());

    // Qualified title chains the codes.
    expect($child->qualifiedTitle())
        ->toBe($grandparent->code . ' › ' . $parent->code . ' › ' . $child->code);
});

it('rejects selecting itself as parent (cycle)', function () {
    $this->actingAs(sh_actAsSuperAdmin());
    $series = sh_series('R');

    Livewire::test(EditSeries::class, ['record' => $series->getRouteKey()])
        ->assertOk()
        ->fillForm(['parent_id' => $series->id])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);

    expect($series->refresh()->parent_id)->toBeNull();
});

it('rejects selecting a descendant as parent (cycle)', function () {
    $this->actingAs(sh_actAsSuperAdmin());

    $parent = sh_series('R');
    $child = sh_series('REG', $parent->id);

    // Trying to move the parent under its own child would form a cycle.
    Livewire::test(EditSeries::class, ['record' => $parent->getRouteKey()])
        ->assertOk()
        ->fillForm(['parent_id' => $child->id])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);

    expect($parent->refresh()->parent_id)->toBeNull();
});

it('filters to top-level series only', function () {
    $this->actingAs(sh_actAsSuperAdmin());

    $root = sh_series('R');
    $sub = sh_series('REG', $root->id);

    Livewire::test(ListSeries::class)
        ->filterTable('top_level', true)
        ->assertCanSeeTableRecords([$root])
        ->assertCanNotSeeTableRecords([$sub]);
});
