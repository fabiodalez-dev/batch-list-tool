<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\DisinfestationCycleReport;
use App\Models\Box;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Q1 (NAF Queries) — the disinfestation cycle plan lists boxes due for
 * disinfestation: never-disinfested + those past the 40-day cycle, and hides
 * boxes disinfested recently (still current).
 */
uses(RefreshDatabase::class);

function cycleAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin'); // Gate::before grants super_admin every ability.

    return $u;
}

it('renders the cycle report for a report-viewer', function (): void {
    $this->actingAs(cycleAdmin());

    Livewire::test(DisinfestationCycleReport::class)->assertOk();
});

it('lists never-disinfested and overdue boxes but hides recently-disinfested ones', function (): void {
    $this->actingAs(cycleAdmin());

    $never = Box::factory()->create(['disinfestation_date' => null]);
    $overdue = Box::factory()->create(['disinfestation_date' => now()->subDays(90)]);
    $due = Box::factory()->create(['disinfestation_date' => now()->subDays(50)]);
    $current = Box::factory()->create(['disinfestation_date' => now()->subDays(10)]);

    Livewire::test(DisinfestationCycleReport::class)
        ->assertCanSeeTableRecords([$never, $overdue, $due])
        ->assertCanNotSeeTableRecords([$current]);
});

it('orders never-disinfested boxes before re-cycle boxes', function (): void {
    $this->actingAs(cycleAdmin());

    $recycled = Box::factory()->create(['disinfestation_date' => now()->subDays(90)]);
    $never = Box::factory()->create(['disinfestation_date' => null]);

    Livewire::test(DisinfestationCycleReport::class)
        ->assertCanSeeTableRecords([$never, $recycled], inOrder: true);
});

it('classifies a box at exactly 80 days as Overdue only, not Due (filter boundary)', function (): void {
    $this->actingAs(cycleAdmin());

    // Exactly OVERDUE_DAYS ago → status() says Overdue; the two filters must not overlap.
    $boundary = Box::factory()->create(['disinfestation_date' => now()->subDays(80)->startOfDay()]);

    Livewire::test(DisinfestationCycleReport::class)
        ->filterTable('cycle_status', 'overdue')
        ->assertCanSeeTableRecords([$boundary]);

    Livewire::test(DisinfestationCycleReport::class)
        ->filterTable('cycle_status', 'due')
        ->assertCanNotSeeTableRecords([$boundary]);
});
