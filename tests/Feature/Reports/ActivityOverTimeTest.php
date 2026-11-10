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
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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
    $kinds = fn (): array => array_map(fn ($w) => $w->getProperties()['kind'], $page->charts());

    expect($kinds())->toContain('changes');

    Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewer = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $viewer->assignRole('viewer');
    $this->actingAs($viewer);

    expect(in_array('changes', $kinds(), true))->toBe($viewer->can('view_any_audit'));
});

it('draws the months of a chosen range, turned round when reversed (client 2026-11-10)', function (): void {
    Carbon::setTestNow('2026-11-10 10:00:00');

    expect(ActivitySeries::months(12, '2026-01', '2026-03'))->toBe(['2026-01', '2026-02', '2026-03'])
        ->and(ActivitySeries::months(12, '2026-03', '2026-01'))->toBe(['2026-01', '2026-02', '2026-03'])
        ->and(ActivitySeries::months(12, '2026-09', null))->toBe(['2026-09', '2026-10', '2026-11'])
        ->and(ActivitySeries::months(12, null, '2026-03'))->toHaveCount(12)
        ->and(ActivitySeries::months(12, 'nonsense', '2026-13'))->toHaveCount(12)
        ->and(ActivitySeries::months(12, '1990-01', '2026-11'))->toHaveCount(ActivitySeries::MAX_MONTHS);
});

it('counts the documents the repository held at the end of each month', function (): void {
    Carbon::setTestNow('2026-11-10 10:00:00');
    $doc = fn (string $id, string $created): Document => tap(Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => $id, 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => 'T']), function (Document $d) use ($created): void {
        $d->forceFill(['created_at' => $created])->saveQuietly();
    });
    $doc('C-0', '2025-12-20');
    $doc('C-1', '2026-02-03');
    $doc('C-2', '2026-02-25');
    $gone = $doc('C-3', '2026-03-01');
    $gone->delete();
    $gone->forceFill(['deleted_at' => '2026-04-02'])->saveQuietly();

    $totals = ActivitySeries::collection(ActivitySeries::months(12, '2026-01', '2026-05'))['Documents in the repository'];

    expect($totals)->toBe([1, 3, 4, 3, 3]);
});

it('passes the chosen range to the charts of the page', function (): void {
    Carbon::setTestNow('2026-11-10 10:00:00');

    Livewire::test(ActivityChart::class, ['kind' => 'collection', 'pageFilters' => ['from' => '2026-06', 'to' => '2026-08']])
        ->assertOk()
        ->assertSee('Documents in the repository');

    Livewire::test(ActivityOverTimeReport::class)
        ->assertOk()
        ->assertSee('From month')
        ->assertSee('To month');
});

it('locks the chart kind and refuses the audit series to those who may not read it', function (): void {
    Livewire::test(ActivityChart::class, ['kind' => 'moves'])
        ->set('kind', 'changes')
        ->assertStatus(403);
})->throws(CannotUpdateLockedPropertyException::class);

it('counts cataloguing only for documents the user may see', function (): void {
    $other = Repository::factory()->create(['code' => 'AOT2']);
    $mine = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'AOT-M', 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => 'T']);
    $theirs = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'AOT-T', 'repository_id' => $other->id, 'series_id' => $this->series->id, 'document_type' => 'T']);
    $mine->update(['catalogue_identifier' => 'CAT-M']);
    $theirs->update(['catalogue_identifier' => 'CAT-T']);

    Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
    $editor = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $editor->assignRole('editor');
    $editor->repositories()->syncWithoutDetaching([$this->repo->id => ['is_default' => true]]);
    $this->actingAs($editor);

    expect(array_sum(ActivitySeries::cataloguing()['Documents catalogued']))->toBe(1);
});
