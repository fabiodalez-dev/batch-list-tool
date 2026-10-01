<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\ActivityOverTimeReport;
use App\Filament\Widgets\Activity\ActivityChart;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\Reports\ActivitySeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use OwenIt\Auditing\AuditableObserver;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['audit.console' => true]);
    Document::observe(AuditableObserver::class);
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->repo = Repository::factory()->create(['code' => 'AOT']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    $this->batch = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '9501', 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $this->series = Series::create(['code' => 'AOTS', 'title' => 'S', 'is_active' => true, 'repository_id' => $this->repo->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('covers the last twelve months, oldest first, empty months as zero', function (): void {
    Carbon::setTestNow('2026-10-15 10:00:00');

    $months = ActivitySeries::months();

    expect($months)->toHaveCount(12)
        ->and($months[0])->toBe('2025-11')
        ->and(end($months))->toBe('2026-10')
        ->and(ActivitySeries::disinfestations()['Boxes disinfested'])->toBe(array_fill(0, 12, 0));
});

it('counts disinfestations in the month they happened, and leaves out older ones', function (): void {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $box = fn (string $n, string $date): Box => Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => $n, 'batch_id' => $this->batch->id, 'barcode' => 'AO' . $n, 'barcode_status' => 'IN', 'is_legacy' => false, 'disinfestation_date' => $date]);
    $box('1', '2026-10-01');
    $box('2', '2026-10-30');
    $box('3', '2026-03-12');
    $box('4', '2024-01-01'); // outside the window

    $series = ActivitySeries::disinfestations()['Boxes disinfested'];
    $months = ActivitySeries::months();

    expect($series[array_search('2026-10', $months, true)])->toBe(2)
        ->and($series[array_search('2026-03', $months, true)])->toBe(1)
        ->and(array_sum($series))->toBe(3);
});

it('counts box moves between locations, not where a box started', function (): void {
    $a = Location::factory()->create(['code' => 'AOT-A', 'name' => 'A', 'repository_id' => $this->repo->id]);
    $b = Location::factory()->create(['code' => 'AOT-B', 'name' => 'B', 'repository_id' => $this->repo->id]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => 'M1', 'batch_id' => $this->batch->id, 'barcode' => 'AOM1', 'barcode_status' => 'IN', 'is_legacy' => false, 'location_id' => $a->id]);
    $box->update(['location_id' => $b->id]);
    $box->update(['location_id' => $a->id]);

    expect(array_sum(ActivitySeries::moves()['Boxes moved between locations']))->toBe(2);
});

it('counts a document as catalogued in the month its catalogue identifier was first given', function (): void {
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'AOT-1', 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => 'T']);
    $doc->update(['catalogue_identifier' => 'CAT-1']);
    $doc->update(['catalogue_identifier' => 'CAT-1-corrected']); // a correction, not a new cataloguing

    expect(Audit::query()->where('auditable_type', Document::class)->where('event', 'updated')->count())->toBe(2)
        ->and(array_sum(ActivitySeries::cataloguing()['Documents catalogued']))->toBe(1);
});

it('opens the report with its charts, none of them polling', function (): void {
    $this->get(ActivityOverTimeReport::getUrl())->assertOk()->assertSee('Activity over time');

    foreach (['changes', 'moves', 'disinfestations', 'cataloguing'] as $kind) {
        $html = Livewire::test(ActivityChart::class, ['kind' => $kind])->assertOk()->html();
        expect($html)->not->toContain('wire:poll', "the {$kind} chart polls");
    }
});

it('hides the whole-archive changes chart from those who may not read the audit trail', function (): void {
    $page = new ActivityOverTimeReport;
    $kinds = fn (): array => array_map(fn ($w) => $w->getProperties()['kind'], (fn () => $this->getHeaderWidgets())->call($page));

    expect($kinds())->toContain('changes');

    Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewer = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $viewer->assignRole('viewer');
    $this->actingAs($viewer);

    expect(in_array('changes', $kinds(), true))->toBe($viewer->can('view_any_audit'));
});
