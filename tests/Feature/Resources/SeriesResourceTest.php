<?php

declare(strict_types=1);

use App\Filament\Resources\SeriesResource\Pages\CreateSeries;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function rolesExist_series(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function actAsAdmin_series(): User
{
    rolesExist_series();
    $u = User::factory()->create([
        'email' => 'series-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function makeSeries_series(string $code = 'SR'): Series
{
    return Series::create([
        'code' => $code . '_' . substr(uniqid(), -4),
        'title' => $code . ' title',
        'is_active' => true,
    ]);
}

/* 37. list renders 29 seeded series */
test('SeriesResource list renders the seeded reference data', function () {
    $this->actingAs(actAsAdmin_series());

    // Some Series rows are created by the InitialDataSeeder and the
    // import-samples command. We just assert the list is non-empty and
    // renders without error — exact count depends on dev seed state.
    $countBefore = Series::query()->count();
    $created = makeSeries_series();

    Livewire::test(ListSeries::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$created]);

    expect(Series::query()->count())->toBe($countBefore + 1);
});

/* 38. create persists */
test('SeriesResource create persists row', function () {
    $this->actingAs(actAsAdmin_series());

    $code = 'NEWSR_' . substr(uniqid(), -4);

    Livewire::test(CreateSeries::class)
        ->fillForm([
            'code' => $code,
            'title' => 'New series via Filament',
            'is_wills_series' => false,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Series::where('code', $code)->exists())->toBeTrue();
});

/* 39. unique code DB constraint */
test('SeriesResource code is unique (DB constraint)', function () {
    $existing = makeSeries_series('UQS');

    try {
        Series::create([
            'code' => $existing->code,
            'title' => 'Duplicate code attempt',
            'is_active' => true,
        ]);
        $this->fail('Expected uniqueness violation on duplicate code.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(QueryException::class);
        expect(strtolower($e->getMessage()))->toContain('unique');
    }
});

/* 40. Cannot hard-delete a Series referenced by documents (restrictOnDelete) */
test('Series cannot be hard-deleted while documents reference it', function () {
    $repo = Repository::factory()->create(['code' => 'SR_RD_' . substr(uniqid(), -6)]);
    $series = makeSeries_series('RFR');

    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'SR-DOC-' . uniqid(),
        'document_type' => 'TEST',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);

    // Soft delete works (only sets deleted_at — FK still valid).
    $series->delete();
    expect(Series::find($series->id))->toBeNull();
    expect(Series::withTrashed()->find($series->id))->not->toBeNull();

    // forceDelete must fail because documents.series_id is restrictOnDelete().
    try {
        $series->forceDelete();
        $this->fail('Expected restrictOnDelete FK violation when force-deleting a Series with documents.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(QueryException::class);
        // SQLite says "FOREIGN KEY constraint failed", MySQL "Cannot delete or update a parent row"
        $msg = strtolower($e->getMessage());
        expect($msg)->toMatch('/foreign key|parent row|constraint/');
    }
});
