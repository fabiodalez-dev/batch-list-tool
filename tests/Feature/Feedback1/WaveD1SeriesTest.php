<?php

declare(strict_types=1);

use App\Models\DocumentType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Wave D1 — Series repository_id + N:N DocumentType pivot.
 *
 * Points covered (4+ per group):
 *   Schema   — repository_id column nullable; pivot table + unique constraint
 *   Model    — Series.repository() relation; Series.documentTypes() relation
 *   Pivot    — attach/detach/unique enforcement via documentTypes()
 */
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function wd1_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'WD1-' . strtoupper(substr(uniqid(), -6)),
    ]);
}

function wd1_series(array $attrs = []): Series
{
    return Series::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'code' => 'WD1-' . strtoupper(substr(uniqid(), -4)),
        'title' => 'Wave D1 series',
        'is_wills_series' => false,
        'is_active' => true,
    ], $attrs));
}

function wd1_doctype(string $name = ''): DocumentType
{
    return DocumentType::create([
        'name' => $name !== '' ? $name : 'DT-WD1-' . substr(uniqid(), -6),
        'is_active' => true,
    ]);
}

// ===========================================================================
// Schema — DB column structure
// ===========================================================================

it('D1-Schema.1: series.repository_id column exists and is nullable', function (): void {
    $schema = DB::getSchemaBuilder();
    expect($schema->hasColumn('series', 'repository_id'))->toBeTrue();

    // Creating a series without repository_id succeeds (nullable)
    $s = wd1_series(['repository_id' => null]);
    expect($s->repository_id)->toBeNull();
});

it('D1-Schema.2: document_type_series pivot table exists', function (): void {
    expect(DB::getSchemaBuilder()->hasTable('document_type_series'))->toBeTrue();
});

it('D1-Schema.3: document_type_series has the expected columns', function (): void {
    $schema = DB::getSchemaBuilder();
    foreach (['id', 'document_type_id', 'series_id', 'created_at', 'updated_at'] as $col) {
        expect($schema->hasColumn('document_type_series', $col))
            ->toBeTrue("Column {$col} missing from document_type_series");
    }
});

it('D1-Schema.4: pivot enforces unique (document_type_id, series_id) pair', function (): void {
    $series = wd1_series();
    $dt = wd1_doctype();

    $series->documentTypes()->attach($dt->id);

    expect(fn () => $series->documentTypes()->attach($dt->id))
        ->toThrow(QueryException::class);
});

// ===========================================================================
// Model — Series relations
// ===========================================================================

it('D1-Model.1: series stores and retrieves repository_id correctly', function (): void {
    $repo = wd1_repo();
    $s = wd1_series(['repository_id' => $repo->id]);

    $s->refresh();
    expect($s->repository_id)->toBe($repo->id);
});

it('D1-Model.2: series belongsTo repository resolves the model', function (): void {
    $repo = wd1_repo();
    $s = wd1_series(['repository_id' => $repo->id]);

    $s->load('repository');
    expect($s->repository)->not->toBeNull();
    expect($s->repository->id)->toBe($repo->id);
});

it('D1-Model.3: series documentTypes attach/detach works', function (): void {
    $s = wd1_series();
    $dt1 = wd1_doctype('Registers');
    $dt2 = wd1_doctype('Originals');

    $s->documentTypes()->attach([$dt1->id, $dt2->id]);
    expect($s->documentTypes()->count())->toBe(2);

    $s->documentTypes()->detach($dt1->id);
    expect($s->documentTypes()->count())->toBe(1);
    expect($s->documentTypes()->where('document_types.id', $dt2->id)->exists())->toBeTrue();
});

it('D1-Model.4: documentType series() inverse relation works', function (): void {
    $s1 = wd1_series();
    $s2 = wd1_series();
    $dt = wd1_doctype('Wills');

    $dt->series()->attach([$s1->id, $s2->id]);
    expect($dt->series()->count())->toBe(2);

    $ids = $dt->series()->pluck('series.id')->sort()->values()->all();
    expect($ids)->toContain($s1->id);
    expect($ids)->toContain($s2->id);
});

it('D1-Model.5: pivot rows carry timestamps', function (): void {
    $s = wd1_series();
    $dt = wd1_doctype();

    $s->documentTypes()->attach($dt->id);

    $row = DB::table('document_type_series')
        ->where('series_id', $s->id)
        ->where('document_type_id', $dt->id)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->created_at)->not->toBeNull();
    expect($row->updated_at)->not->toBeNull();
});
