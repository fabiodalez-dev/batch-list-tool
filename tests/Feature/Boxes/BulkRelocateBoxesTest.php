<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Models\Batch;
use App\Models\Box;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Client request (2026-07-22 meeting): moving one or more boxes from one
 * location to another, self-service, from the Boxes table.
 *
 * The per-row "Move to location" action and the "Relocate boxes" bulk action
 * already exist; this pins the exact client scenario — a PURE relocate of
 * several selected boxes to a new location, WITHOUT marking them PERM OUT.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function brb_admin(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

it('bulk-relocates several selected boxes to a new location (location only, no PERM OUT)', function (): void {
    $this->actingAs(brb_admin());

    $location = qf_location();
    $boxA = qf_box(['notes' => null]);
    $boxB = qf_box(['notes' => 'existing note']);
    $boxC = qf_box(['notes' => null]);
    $originalStatus = $boxA->barcode_status;

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('relocate', [$boxA, $boxB, $boxC], data: [
            'location_id' => $location->id,
            'set_perm_out' => false,
            'tracking_note' => 'Moved shelf A → shelf B',
        ]);

    foreach ([$boxA, $boxB, $boxC] as $box) {
        $fresh = $box->fresh();
        expect($fresh->location_id)->toBe($location->id)
            // A pure relocate must NOT touch custody status.
            ->and($fresh->barcode_status)->toBe($originalStatus)
            ->and((string) $fresh->notes)->toContain('Moved shelf A → shelf B');
    }
});

it('bulk-relocates a single selected box too', function (): void {
    $this->actingAs(brb_admin());

    $location = qf_location();
    $box = qf_box();

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('relocate', [$box], data: [
            'location_id' => $location->id,
            'set_perm_out' => false,
        ]);

    expect($box->fresh()->location_id)->toBe($location->id);
});

it('rejects a forged bulk relocate onto an INACTIVE location (no box moved)', function (): void {
    $this->actingAs(brb_admin());

    $inactive = qf_location(attrs: ['is_active' => false]);
    $boxA = qf_box();
    $boxB = qf_box();

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('relocate', [$boxA, $boxB], data: [
            'location_id' => $inactive->id,
            'set_perm_out' => false,
        ]);

    // Guard mirrors the per-row action: an inactive target is refused wholesale.
    expect($boxA->fresh()->location_id)->not->toBe($inactive->id)
        ->and($boxB->fresh()->location_id)->not->toBe($inactive->id);
});

it('skips boxes whose repository differs from a repository-scoped target location', function (): void {
    $this->actingAs(brb_admin());

    $repoA = qf_repo('RA');
    $repoB = qf_repo('RB');
    // Location scoped to repo A; the box's batch lives in repo B (that batch is
    // what customFieldRepositoryId() reads) → the box must be skipped.
    $locationA = qf_location($repoA->id);
    $batchB = Batch::factory()->create(['repository_id' => $repoB->id]);
    $box = qf_box(['batch_id' => $batchB->id]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('relocate', [$box], data: [
            'location_id' => $locationA->id,
            'set_perm_out' => false,
        ]);

    expect($box->fresh()->location_id)->not->toBe($locationA->id);
});
