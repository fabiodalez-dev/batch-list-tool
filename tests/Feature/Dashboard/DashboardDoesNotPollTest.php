<?php

declare(strict_types=1);

use App\Filament\Widgets\DocumentsPerBatchChart;
use App\Filament\Widgets\DocumentsPerSeriesChart;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The cached dashboard widgets must not poll the server.
 *
 * Filament gives stats and chart widgets `wire:poll.5s` by default. Ours read
 * a 5-minute cache, so every poll returned the same numbers: 36 requests a
 * minute for each open dashboard tab, on shared hosting, for nothing.
 */
uses(RefreshDatabase::class);

it('renders the cached widget without a poll', function (string $widget): void {
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $repo = Repository::factory()->create(['code' => 'DNP']);
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    $this->actingAs($u);

    $html = Livewire::test($widget)->html();

    expect($html)->not->toContain('wire:poll');
})->with([
    'overview' => [StatsOverviewWidget::class],
    'documents by subseries' => [DocumentsPerSeriesChart::class],
    'top batches' => [DocumentsPerBatchChart::class],
]);
