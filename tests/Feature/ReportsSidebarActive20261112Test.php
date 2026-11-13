<?php

declare(strict_types=1);

use App\Filament\Pages\Reports;
use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\StockTakeReport;
use App\Models\Repository;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * 2026-11-12: with a report open, no sidebar item was highlighted. The report
 * pages have no item of their own; "Reports" now stays active on them.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');
    $this->actingAs($user);
    $this->withoutVite();
});

/**
 * The href of every highlighted sidebar item in $html.
 *
 * @return list<string>
 */
function activeSidebarLinks(string $html): array
{
    preg_match_all('/<li[^>]*class="[^"]*fi-sidebar-item[^"]*fi-active[^"]*"[^>]*>\s*<a[^>]*href="([^"]+)"/s', $html, $m);

    return $m[1];
}

it('covers every report page the landing page links to', function (): void {
    $page = new Reports;
    $linked = collect($page->cards())->pluck('url')->all();
    $covered = array_map(fn (string $class): string => $class::getUrl(), Reports::REPORT_PAGES);

    expect(array_diff($linked, $covered))->toBe([]);
});

it('highlights "Reports" in the sidebar when a report is open', function (string $report): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $reportsUrl = Reports::getUrl();

    $html = $this->get($report::getUrl())->assertOk()->getContent();

    expect(activeSidebarLinks($html))->toBe([$reportsUrl]);
})->with([
    'Documents by batch' => [DocumentsByBatchReport::class],
    'Stock take' => [StockTakeReport::class],
]);

it('still highlights "Reports" on the landing page itself', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $html = $this->get(Reports::getUrl())->assertOk()->getContent();

    expect(activeSidebarLinks($html))->toBe([Reports::getUrl()]);
});
