<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource;
use App\Filament\Resources\BoxResource\Pages\CreateBox;
use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Support\CreatorColumn;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\User;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * A6, A7, A9, A10 (Wave A) — Box area Feedback 1 spec items.
 *
 *  A10: barcode required everywhere + globally unique (form validation).
 *   A6: columns in requested order; reorderableColumns(); all toggleable.
 *   A7: filters layout BeforeContentCollapsible (visible when result set empty).
 *   A9: CreatorColumn (Inputter) present in column list.
 *   Sorting: per-column sortable() applied.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

// ---------------------------------------------------------------------------
// Shared factory helpers
// ---------------------------------------------------------------------------

function ba_actor(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $repo = Repository::factory()->create();
    $u = User::factory()->create([
        'email' => 'ba-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');
    $u->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);

    return $u;
}

function ba_batchFor(User $user): Batch
{
    return Batch::factory()->create(['repository_id' => $user->default_repository_id]);
}

// ---------------------------------------------------------------------------
// A10 — Barcode required
// ---------------------------------------------------------------------------

it('A10: barcode is required for every box type via the create form', function (): void {
    $user = ba_actor();
    $this->actingAs($user);

    $batch = ba_batchFor($user);

    Livewire::test(CreateBox::class)
        ->fillForm([
            'box_type' => 'RAS',
            'batch_id' => $batch->id,
            'box_number' => '99',
            'barcode' => null,     // <-- intentionally empty
            'barcode_status' => 'IN',
            'is_legacy' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['barcode']);
});

it('A10: null barcode is rejected for an IN_SITU box via the create form', function (): void {
    $user = ba_actor();
    $this->actingAs($user);

    Livewire::test(CreateBox::class)
        ->fillForm([
            'box_type' => 'IN_SITU',
            'provenance_unknown' => true,
            'box_number' => 'NRA-T1',
            'barcode' => null,     // <-- intentionally empty
            'barcode_status' => 'IN',
            'is_legacy' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['barcode']);
});

// ---------------------------------------------------------------------------
// A10 — Barcode globally unique (form validation)
// ---------------------------------------------------------------------------

it('A10: duplicate barcode is rejected via the create form with a friendly message', function (): void {
    $user = ba_actor();
    $this->actingAs($user);

    $batch = ba_batchFor($user);

    // Seed an existing box with barcode 'TAKEN-BC-1'.
    Box::factory()->create([
        'batch_id' => $batch->id,
        'barcode' => 'TAKEN-BC-1',
        'box_number' => '1',
    ]);

    Livewire::test(CreateBox::class)
        ->fillForm([
            'box_type' => 'RAS',
            'batch_id' => $batch->id,
            'box_number' => '2',
            'barcode' => 'TAKEN-BC-1',  // <-- duplicate
            'barcode_status' => 'IN',
            'is_legacy' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['barcode']);
});

it('A10: same barcode value passes validation on edit of the same box', function (): void {
    $user = ba_actor();
    $this->actingAs($user);

    $batch = ba_batchFor($user);

    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'barcode' => 'EDIT-BC-1',
        'box_number' => '10',
    ]);

    // Saving the box with its OWN barcode must NOT trigger the uniqueness error.
    Livewire::test(EditBox::class, ['record' => $box->getRouteKey()])
        ->fillForm([
            'barcode' => 'EDIT-BC-1',
        ])
        ->call('save')
        ->assertHasNoFormErrors(['barcode']);
});

// ---------------------------------------------------------------------------
// A6 — Column order + reorderableColumns + toggleable
// ---------------------------------------------------------------------------

it('A6: table has reorderableColumns enabled on the BoxResource table', function (): void {
    $user = ba_actor();
    $this->actingAs($user);

    // Mount the list page and retrieve the resolved table to inspect flags.
    $livewire = Livewire::test(ListBoxes::class);

    // reorderableColumns() means Filament wires up the column manager.
    // We assert it does not throw and the table is correctly configured
    // by checking the table instance on the component.
    /** @var Table $table */
    $table = BoxResource::table(
        Table::make($livewire->instance())
    );

    expect($table->hasColumnManager())->toBeTrue();
});

it('A6: column names appear in the requested order: Batch, Box, Barcode, Barcode Status, Disinfestation Date, Box Type, Destroyed, Parent Box Id, Is Legacy', function (): void {
    $user = ba_actor();
    $this->actingAs($user);

    $livewire = Livewire::test(ListBoxes::class)->instance();

    /** @var Table $table */
    $table = BoxResource::table(
        Table::make($livewire)
    );

    $columnNames = collect($table->getColumns())->keys()->all();

    // The first 9 fixed columns must follow the spec order.
    $expectedPrefix = [
        'batch.batch_number',
        'box_number',
        'barcode',
        'barcode_status',
        'disinfestation_date',
        'box_type',
        'destroyed_at',
        'parent_box_id',
        'is_legacy',
    ];

    foreach ($expectedPrefix as $i => $name) {
        expect($columnNames[$i])->toBe(
            $name,
            "Column at index {$i} should be '{$name}', got '{$columnNames[$i]}'"
        );
    }
});

it('A6: every core column is toggleable', function (): void {
    $user = ba_actor();
    $this->actingAs($user);

    $livewire = Livewire::test(ListBoxes::class)->instance();

    /** @var Table $table */
    $table = BoxResource::table(
        Table::make($livewire)
    );

    $mustBeToggleable = [
        'batch.batch_number', 'box_number', 'barcode',
        'barcode_status', 'disinfestation_date', 'box_type',
        'destroyed_at', 'parent_box_id', 'is_legacy',
    ];

    foreach ($mustBeToggleable as $colName) {
        $col = $table->getColumn($colName);
        expect($col)->not->toBeNull("Column '{$colName}' not found in table")
            ->and($col->isToggleable())->toBeTrue("Column '{$colName}' must be toggleable");
    }
});

// ---------------------------------------------------------------------------
// A7 — Filters visible when result set is empty
// ---------------------------------------------------------------------------

it('A7: box table uses BeforeContentCollapsible filters layout so filters are always accessible', function (): void {
    $user = ba_actor();
    $this->actingAs($user);

    $livewire = Livewire::test(ListBoxes::class)->instance();

    /** @var Table $table */
    $table = BoxResource::table(
        Table::make($livewire)
    );

    expect($table->getFiltersLayout())->toBe(FiltersLayout::BeforeContentCollapsible);
});

// ---------------------------------------------------------------------------
// A9 — Inputter / CreatorColumn present
// ---------------------------------------------------------------------------

it('A9: CreatorColumn (Inputter) is present in the box table columns', function (): void {
    $user = ba_actor();
    $this->actingAs($user);

    $livewire = Livewire::test(ListBoxes::class)->instance();

    /** @var Table $table */
    $table = BoxResource::table(
        Table::make($livewire)
    );

    $col = $table->getColumn('inputter');

    expect($col)->not->toBeNull('Expected an "inputter" column from CreatorColumn::make()')
        ->and($col->getLabel())->toBe('Inputter')
        ->and($col->isToggleable())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Sorting — per-column sortable()
// ---------------------------------------------------------------------------

it('per-column sorting: batch.batch_number, barcode_status, disinfestation_date are sortable', function (): void {
    $user = ba_actor();
    $this->actingAs($user);

    $livewire = Livewire::test(ListBoxes::class)->instance();

    /** @var Table $table */
    $table = BoxResource::table(
        Table::make($livewire)
    );

    foreach (['batch.batch_number', 'barcode_status', 'disinfestation_date', 'box_type', 'destroyed_at', 'is_legacy'] as $colName) {
        $col = $table->getColumn($colName);
        expect($col)->not->toBeNull("Column '{$colName}' not found")
            ->and($col->isSortable())->toBeTrue("Column '{$colName}' must be sortable");
    }
});
