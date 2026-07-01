<?php

declare(strict_types=1);

use App\Filament\Resources\BatchResource;
use App\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Models\User;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table as FilamentTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 gaps — Batch UI tests.
 *
 * Covers:
 *  1. "Batch Type" renamed to "Accession Type" on the Batch form and table
 *     (Lookups nav was already renamed; this aligns the Batch resource).
 *  2. All main table columns are toggleable EXCEPT batch_number (the
 *     key/hyperlink column stays fixed).
 *  3. Filters stay visible above the table content so a null result set
 *     never hides them (FiltersLayout::BeforeContentCollapsible, mirroring
 *     BoxResource).
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

// ---------------------------------------------------------------------------
// Shared helpers (file-local, prefixed to avoid collisions with other tests)
// ---------------------------------------------------------------------------

function f1gb_superAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'f1gb-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function f1gb_table(): FilamentTable
{
    $livewire = Livewire::test(ListBatches::class)->instance();

    return BatchResource::table(
        FilamentTable::make($livewire),
    );
}

// ============================================================================
// 1 — "Accession Type" label
// ============================================================================

it('labels the type table column "Accession Type"', function (): void {
    $this->actingAs(f1gb_superAdmin());

    $col = f1gb_table()->getColumn('type');

    expect($col)->not->toBeNull('type column must exist in BatchResource table');
    expect($col->getLabel())->toBe('Accession Type');
});

it('shows the "Accession Type" label on the create form', function (): void {
    $this->actingAs(f1gb_superAdmin());

    Livewire::test(CreateBatch::class)
        ->assertSee('Accession Type');
});

// ============================================================================
// 2 — toggleable columns (all main columns except batch_number)
// ============================================================================

it('keeps the batch_number key column fixed (not toggleable)', function (): void {
    $this->actingAs(f1gb_superAdmin());

    $col = f1gb_table()->getColumn('batch_number');

    expect($col)->not->toBeNull('batch_number column must exist');
    expect($col->isToggleable())->toBeFalse(
        'batch_number is the key/hyperlink column and must stay fixed',
    );
});

it('makes all other main columns toggleable', function (): void {
    $this->actingAs(f1gb_superAdmin());

    $table = f1gb_table();

    foreach (['description', 'type', 'repository.name', 'is_active', 'inputter'] as $name) {
        $col = $table->getColumn($name);

        expect($col)->not->toBeNull("Column '{$name}' must exist in BatchResource table");
        expect($col->isToggleable())->toBeTrue(
            "Column '{$name}' must be toggleable so operators can remove preset columns",
        );
    }
});

// ============================================================================
// 3 — filters always visible (BeforeContentCollapsible)
// ============================================================================

it('uses the BeforeContentCollapsible filters layout (mirrors BoxResource)', function (): void {
    $this->actingAs(f1gb_superAdmin());

    expect(f1gb_table()->getFiltersLayout())->toBe(FiltersLayout::BeforeContentCollapsible);
});
