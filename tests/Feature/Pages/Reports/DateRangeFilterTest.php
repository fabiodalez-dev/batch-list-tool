<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\Filters\DateRangeFilter;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Universal DateRangeFilter — RFQ §3.2 reporting enhancement.
 *
 * Asserts the three boundary semantics:
 *   - from + to → whereBetween()
 *   - from only → where(>=)
 *   - to only   → where(<=)  (inclusive end-of-day)
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function drf_user(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'drf-' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function drf_repo(): Repository
{
    return Repository::factory()->create(['code' => 'DRF_' . substr(uniqid(), -6)]);
}

function drf_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'DRF_' . substr(uniqid(), -4)],
        ['title' => 'DRF series', 'is_active' => true],
    );
}

function drf_doc_with_created(int $repoId, int $seriesId, string $createdAt, string $identifier): Document
{
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => $identifier,
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ]);

    // Force a deterministic created_at by writing directly to the row.
    // saveQuietly() bypasses observers; the timestamp column is freshly
    // assigned via forceFill() which ignores fillable rules.
    $doc->timestamps = false;
    $doc->forceFill(['created_at' => $createdAt])->saveQuietly();
    $doc->timestamps = true;

    return $doc->refresh();
}

test('DateRangeFilter with `from` only returns rows where the column is >= from', function () {
    $this->actingAs(drf_user());

    $repo = drf_repo();
    $series = drf_series();

    $before = drf_doc_with_created($repo->id, $series->id, '2024-01-01 12:00:00', 'DRF-OLD');
    $after = drf_doc_with_created($repo->id, $series->id, '2025-06-15 12:00:00', 'DRF-NEW');

    $filter = DateRangeFilter::make('test')->column('documents.created_at');

    $query = Document::query();
    $filter->applyToQuery($query, ['from' => '2025-01-01', 'to' => null]);

    $ids = $query->pluck('documents.id')->all();
    expect($ids)->toContain($after->id)
        ->and($ids)->not->toContain($before->id);
});

test('DateRangeFilter with `to` only returns rows where the column is <= to', function () {
    $this->actingAs(drf_user());

    $repo = drf_repo();
    $series = drf_series();

    $before = drf_doc_with_created($repo->id, $series->id, '2024-01-01 12:00:00', 'DRF-TO-OLD');
    $after = drf_doc_with_created($repo->id, $series->id, '2025-06-15 12:00:00', 'DRF-TO-NEW');

    $filter = DateRangeFilter::make('test')->column('documents.created_at');

    $query = Document::query();
    $filter->applyToQuery($query, ['from' => null, 'to' => '2025-01-01']);

    $ids = $query->pluck('documents.id')->all();
    expect($ids)->toContain($before->id)
        ->and($ids)->not->toContain($after->id);
});

test('DateRangeFilter with both bounds returns rows in the closed interval', function () {
    $this->actingAs(drf_user());

    $repo = drf_repo();
    $series = drf_series();

    $before = drf_doc_with_created($repo->id, $series->id, '2023-12-31 12:00:00', 'DRF-BEFORE');
    $inside = drf_doc_with_created($repo->id, $series->id, '2024-06-15 12:00:00', 'DRF-INSIDE');
    $after = drf_doc_with_created($repo->id, $series->id, '2025-06-15 12:00:00', 'DRF-AFTER');

    $filter = DateRangeFilter::make('test')->column('documents.created_at');

    $query = Document::query();
    $filter->applyToQuery($query, ['from' => '2024-01-01', 'to' => '2024-12-31']);

    $ids = $query->pluck('documents.id')->all();
    expect($ids)->toContain($inside->id)
        ->and($ids)->not->toContain($before->id)
        ->and($ids)->not->toContain($after->id);
});
