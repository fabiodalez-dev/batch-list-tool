<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxBarcodeHistory;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

it('writes exactly ONE barcode-history row per status change (no double-write)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'box_type' => 'RAS',
        'barcode_status' => 'IN',
        'barcode' => 'BC-IMM-1',
    ]);

    $box->update(['barcode_status' => 'OUT']);

    // The observer is the single write path: exactly one row, no duplicate
    // from a (now read-only) form repeater.
    expect(BoxBarcodeHistory::where('box_id', $box->id)->count())->toBe(1);
    $row = BoxBarcodeHistory::where('box_id', $box->id)->first();
    expect($row->previous_status)->toBe('IN');
    expect($row->new_status)->toBe('OUT');
});

it('does not persist a manually-filled seal-history row through the box edit form (read-only)', function (): void {
    $repo = Repository::factory()->create();
    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    actingAs($user);

    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'box_type' => 'RAS',
        'barcode_status' => 'IN',
        'barcode' => 'BC-IMM-2',
    ]);

    Livewire::test(EditBox::class, ['record' => $box->getRouteKey()])
        ->fillForm([
            'sealNumberHistory' => [
                ['old_value' => 'SEAL-OLD', 'new_value' => 'SEAL-NEW', 'changed_at' => now(), 'notes' => 'forged'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($box->sealNumberHistory()->where('new_value', 'SEAL-NEW')->exists())->toBeFalse();
});
