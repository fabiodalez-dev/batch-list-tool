<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Activity\ActivityChart;
use App\Filament\Widgets\DocumentsPerSeriesChart;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Models\Repository;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * Client 2026-11-10: "remove pending disinfestation from the dashboard for
 * the time being and put the overview as first thing. Then we will have
 * Changes recorded per month, Documents catalogued per month, Moves per
 * month, Documents by Subseries, and recent activity at the end".
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create();
});

function dashboardWidgetNames(): array
{
    return array_map(
        static fn ($w): string => $w instanceof WidgetConfiguration
            ? $w->widget . ':' . ($w->getProperties()['kind'] ?? '')
            : $w,
        new Dashboard()->getWidgets(),
    );
}

it('lists the dashboard widgets in the order the client asked for', function (): void {
    $this->actingAs(bl_actor('super_admin', $this->repo));

    expect(dashboardWidgetNames())->toBe([
        StatsOverviewWidget::class,
        ActivityChart::class . ':changes',
        ActivityChart::class . ':cataloguing',
        ActivityChart::class . ':moves',
        DocumentsPerSeriesChart::class,
        RecentActivityWidget::class,
    ]);
});

it('has no pending disinfestation widget or top batches chart', function (): void {
    $this->actingAs(bl_actor('super_admin', $this->repo));

    expect(implode(' ', dashboardWidgetNames()))
        ->not->toContain('PendingDisinfestation')
        ->not->toContain('DocumentsPerBatch');
});

it('renders the dashboard without pending disinfestation', function (): void {
    $this->actingAs(bl_actor('super_admin', $this->repo));

    Livewire::test(Dashboard::class)->assertOk()->assertDontSee('Pending disinfestation');
});

it('leaves the changes chart out for a user who cannot read the audit trail', function (): void {
    $user = bl_actor('viewer', $this->repo);
    $role = Role::findOrCreate('dashboard_only', 'web');
    $user->syncRoles([$role]);
    $this->actingAs($user);

    expect(implode(' ', dashboardWidgetNames()))->not->toContain(':changes')->toContain(':cataloguing');
});
