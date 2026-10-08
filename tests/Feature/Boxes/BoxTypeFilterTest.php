<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\BoxesReadyToDestroyReport;
use App\Filament\Pages\Reports\DisinfestationCycleReport;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Lookup\BoxType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\User;
use Filament\Tables\Filters\QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client, 2026-10-08: "I cannot delete MUS boxes. It's not an option in the
 * dropdown." MUS was added on the Box Types page, but the Box type filter
 * listed a fixed set of types, so MUS boxes could not be filtered (and the
 * filter drops a value that is not among its options).
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    bl_seedShieldPermissions();
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    $this->batch = Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(['batch_number' => '19', 'repository_id' => $this->repo->id]);
    BoxType::query()->updateOrCreate(['code' => 'MUS'], ['label' => 'MUS', 'is_active' => true, 'is_legacy' => true, 'sort_order' => 0]);
});

function btf_box(int $batchId, string $type, string $number): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => $type, 'box_number' => $number, 'batch_id' => $batchId,
        'barcode' => 'BT' . $type . $number, 'barcode_status' => 'PERM_OUT', 'is_legacy' => true,
    ]);
}

it('offers a type added on the Box Types page in the box list filter, and filters by it', function (): void {
    $mus = btf_box($this->batch->id, 'MUS', '1');
    $ras = btf_box($this->batch->id, 'RAS', '2');

    expect(BoxType::filterOptions())->toHaveKey('MUS');

    Livewire::test(ListBoxes::class)
        ->set('tableFilters.queryBuilder.rules', [
            'r1' => ['type' => 'box_type', 'data' => ['operator' => 'is', 'settings' => ['values' => ['MUS']]]],
        ])
        ->assertCanSeeTableRecords([$mus])
        ->assertCanNotSeeTableRecords([$ras]);
});

it('keeps an inactive type, and a type only boxes still carry, in the filter', function (): void {
    BoxType::query()->updateOrCreate(['code' => 'OLD'], ['label' => 'Old', 'is_active' => false, 'is_legacy' => true, 'sort_order' => 9]);
    // A type the lookup no longer lists, on an archived box (old data: the
    // model would refuse to save it today).
    $orphan = btf_box($this->batch->id, 'RAS', '3');
    $orphan->delete();
    DB::table('boxes')->where('id', $orphan->id)->update(['box_type' => 'ZZZ']);

    $options = BoxType::filterOptions();

    expect($options)->toHaveKey('OLD')
        ->and($options)->toHaveKey('ZZZ')
        ->and($options)->toHaveKey('RAS');
});

it('offers MUS in the Box type filter of the box reports', function (): void {
    foreach ([BoxesReadyToDestroyReport::class, DisinfestationCycleReport::class] as $page) {
        $options = Livewire::test($page)->instance()->getTable()->getFilter('box_type')->getOptions();
        expect(array_key_exists('MUS', $options))->toBeTrue("MUS missing on {$page}");
    }
});

it('lists the box list filter types from the lookup', function (): void {
    $table = Livewire::test(ListBoxes::class)->instance()->getTable();
    $qb = collect($table->getFilters())->first(fn ($f): bool => $f instanceof QueryBuilder);
    $constraint = collect($qb->getConstraints())->first(fn ($c): bool => $c->getName() === 'box_type');

    expect($constraint->getOptions())->toHaveKey('MUS');
});
