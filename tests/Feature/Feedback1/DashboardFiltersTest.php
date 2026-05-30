<?php

declare(strict_types=1);

use App\Filament\Resources\AuthorityResource;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Filament\Resources\BoxResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 Wave B — advanced dashboard filters.
 *
 *  - B1: every heavy dashboard ships the rich QueryBuilder filter (mechanism #1)
 *        alongside the free-text search (mechanism #2). The QueryBuilder UI is
 *        driven manually in the app; here we assert it is registered and we
 *        exercise the simpler SelectFilter / TernaryFilter / custom-Filter paths
 *        that the same dashboards expose.
 *  - B2: Authority practice-period filter ("worked between X / after X / before
 *        Y") and the "has MS number" ternary.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function df_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'df-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => null,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function df_authority(array $attrs = []): Authority
{
    return Authority::create(array_merge([
        'identifier' => 'R' . random_int(10000, 99999),
        'surname' => 'Sur' . substr(uniqid(), -4),
        'given_names' => 'Given',
        'entity_type' => 'Notary',
    ], $attrs));
}

function df_repo(): Repository
{
    return Repository::factory()->create(['code' => 'DF_' . strtoupper(substr(uniqid(), -6))]);
}

function df_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'DFS_' . substr(uniqid(), -4)],
        ['title' => 'DF series', 'is_active' => true],
    );
}

function df_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'DF-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

/* ============================ B1 — QueryBuilder is registered ============== */

it('registers the QueryBuilder filter on the heavy dashboards (Authority, Document, Box)', function (): void {
    foreach ([AuthorityResource::class, DocumentResource::class, BoxResource::class] as $resource) {
        $table = $resource::table(new Table(app(ListAuthorities::class)));
        $hasQueryBuilder = collect($table->getFilters())
            ->contains(fn ($f): bool => $f instanceof QueryBuilder);

        expect($hasQueryBuilder)->toBeTrue("QueryBuilder filter missing on {$resource}");
    }
});

/* ============================ B1 — Authority entity_type ==================== */

it('Authority "has MS number" ternary narrows both ways', function (): void {
    $this->actingAs(df_actAsSuperAdmin());

    $withMs = df_authority(['identifier' => 'R11111', 'alternative_identifier' => 'MS900']);
    $withoutMs = df_authority(['identifier' => 'R22222', 'alternative_identifier' => null]);

    // true → only creators carrying an MS number
    Livewire::test(ListAuthorities::class)
        ->filterTable('has_ms_number', true)
        ->assertCanSeeTableRecords([$withMs])
        ->assertCanNotSeeTableRecords([$withoutMs]);

    // false → only creators with no MS number
    Livewire::test(ListAuthorities::class)
        ->filterTable('has_ms_number', false)
        ->assertCanSeeTableRecords([$withoutMs])
        ->assertCanNotSeeTableRecords([$withMs]);
});

/* ============================ B2 — practice period ========================= */

it('Authority practice-period filter narrows by a year window (worked between X and Y)', function (): void {
    $this->actingAs(df_actAsSuperAdmin());

    // Worked 1600-1650 → overlaps [1620,1640]
    $inWindow = df_authority(['identifier' => 'R30001', 'practice_dates_start' => 1600, 'practice_dates_end' => 1650]);
    // Worked 1700-1750 → after the window
    $afterWindow = df_authority(['identifier' => 'R30002', 'practice_dates_start' => 1700, 'practice_dates_end' => 1750]);
    // Worked 1500-1550 → before the window
    $beforeWindow = df_authority(['identifier' => 'R30003', 'practice_dates_start' => 1500, 'practice_dates_end' => 1550]);

    Livewire::test(ListAuthorities::class)
        ->filterTable('practice_period', ['from' => 1620, 'to' => 1640])
        ->assertCanSeeTableRecords([$inWindow])
        ->assertCanNotSeeTableRecords([$afterWindow, $beforeWindow]);
});

it('Authority practice-period filter with only "from" keeps notaries who worked after X', function (): void {
    $this->actingAs(df_actAsSuperAdmin());

    $worksLater = df_authority(['identifier' => 'R31001', 'practice_dates_start' => 1700, 'practice_dates_end' => 1750]);
    $worksEarlier = df_authority(['identifier' => 'R31002', 'practice_dates_start' => 1500, 'practice_dates_end' => 1550]);

    Livewire::test(ListAuthorities::class)
        ->filterTable('practice_period', ['from' => 1680])
        ->assertCanSeeTableRecords([$worksLater])
        ->assertCanNotSeeTableRecords([$worksEarlier]);
});

/* ============================ B1 — Document SelectFilters ================== */

it('Document batch SelectFilter narrows to the chosen batch', function (): void {
    $this->actingAs(df_actAsSuperAdmin());

    $repo = df_repo();
    $series = df_series();
    $batchA = Batch::factory()->create(['batch_number' => 101, 'repository_id' => $repo->id]);
    $batchB = Batch::factory()->create(['batch_number' => 102, 'repository_id' => $repo->id]);
    $docA = df_doc($repo->id, $series->id, ['batch_id' => $batchA->id]);
    $docB = df_doc($repo->id, $series->id, ['batch_id' => $batchB->id]);

    Livewire::test(ListDocuments::class)
        ->filterTable('batch', [$batchA->id])
        ->assertCanSeeTableRecords([$docA])
        ->assertCanNotSeeTableRecords([$docB]);
});

it('Document current_box_id SelectFilter narrows to the chosen box', function (): void {
    $this->actingAs(df_actAsSuperAdmin());

    $repo = df_repo();
    $series = df_series();
    $batch = Batch::factory()->create(['batch_number' => 110, 'repository_id' => $repo->id]);
    $boxA = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => '1']);
    $boxB = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => '2']);
    $docA = df_doc($repo->id, $series->id, ['current_box_id' => $boxA->id]);
    $docB = df_doc($repo->id, $series->id, ['current_box_id' => $boxB->id]);

    Livewire::test(ListDocuments::class)
        ->filterTable('current_box_id', [$boxA->id])
        ->assertCanSeeTableRecords([$docA])
        ->assertCanNotSeeTableRecords([$docB]);
});

/* ============================ B1 — Series / Batch SelectFilters ============ */

it('Series code SelectFilter narrows to the chosen series', function (): void {
    $this->actingAs(df_actAsSuperAdmin());

    $sX = Series::create(['code' => 'DFX' . substr(uniqid(), -4), 'title' => 'X', 'is_active' => true]);
    $sY = Series::create(['code' => 'DFY' . substr(uniqid(), -4), 'title' => 'Y', 'is_active' => true]);

    Livewire::test(ListSeries::class)
        ->filterTable('code', [$sX->code])
        ->assertCanSeeTableRecords([$sX])
        ->assertCanNotSeeTableRecords([$sY]);
});

it('Batch type SelectFilter narrows to the chosen type', function (): void {
    $this->actingAs(df_actAsSuperAdmin());

    $repo = df_repo();
    $main = Batch::factory()->create(['batch_number' => 201, 'type' => 'MAIN_COLLECTION', 'repository_id' => $repo->id]);
    $notary = Batch::factory()->create(['batch_number' => 202, 'type' => 'NOTARY_ACCESSION', 'repository_id' => $repo->id]);

    Livewire::test(ListBatches::class)
        ->filterTable('type', ['NOTARY_ACCESSION'])
        ->assertCanSeeTableRecords([$notary])
        ->assertCanNotSeeTableRecords([$main]);
});
