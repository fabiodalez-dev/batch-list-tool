<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();

    $this->repo = Repository::factory()->create();
    $this->batch = Batch::create([
        'batch_number' => 1,
        'repository_id' => $this->repo->id,
        'type' => 'MAIN_COLLECTION',
    ]);
    $this->series = Series::factory()->create();
});

// -- helpers ------------------------------------------------------------------

/** @param array<string,mixed> $overrides */
function dbt_doc(array $overrides = []): Document
{
    // Pull repo + batch from test context where available.
    static $seq = 0;
    $seq++;

    return Document::withoutGlobalScopes()->create(array_merge([
        'identifier' => "DBTTEST-{$seq}",
        'series_id' => null,  // override per-test
        'batch_id' => null,
        'repository_id' => null,
    ], $overrides));
}

// 1 ---------------------------------------------------------------------------
it('records one barcode history row (old=null, new=value) when a document barcode is first set', function () {
    $document = Document::withoutGlobalScopes()->create([
        'identifier' => 'DBT-001',
        'series_id' => $this->series->id,
        'batch_id' => $this->batch->id,
        'repository_id' => $this->repo->id,
        'barcode' => 'DOC-BC-001',
    ]);

    expect($document->barcodeHistory()->count())->toBe(1)
        ->and($document->barcodeHistory()->first()->old_value)->toBeNull()
        ->and($document->barcodeHistory()->first()->new_value)->toBe('DOC-BC-001');
});

// 2 ---------------------------------------------------------------------------
it('records a second history row (old→new) when the barcode changes', function () {
    $document = Document::withoutGlobalScopes()->create([
        'identifier' => 'DBT-002',
        'series_id' => $this->series->id,
        'batch_id' => $this->batch->id,
        'repository_id' => $this->repo->id,
        'barcode' => 'DOC-BC-100',
    ]);

    $document->update(['barcode' => 'DOC-BC-200']);

    $hist = $document->barcodeHistory()->orderBy('id')->get();

    expect($hist)->toHaveCount(2)
        ->and($hist->last()->old_value)->toBe('DOC-BC-100')
        ->and($hist->last()->new_value)->toBe('DOC-BC-200');
});

// 3 ---------------------------------------------------------------------------
it('does NOT record a history row when an unrelated field changes', function () {
    $document = Document::withoutGlobalScopes()->create([
        'identifier' => 'DBT-003',
        'series_id' => $this->series->id,
        'batch_id' => $this->batch->id,
        'repository_id' => $this->repo->id,
        'barcode' => 'DOC-BC-999',
    ]);

    // notes is unrelated — must not trigger another history row.
    $document->update(['notes' => 'Updated notes only']);

    expect($document->barcodeHistory()->count())->toBe(1);
});

// 4 ---------------------------------------------------------------------------
it('stamps the document repository_id onto each history row', function () {
    $document = Document::withoutGlobalScopes()->create([
        'identifier' => 'DBT-004',
        'series_id' => $this->series->id,
        'batch_id' => $this->batch->id,
        'repository_id' => $this->repo->id,
        'barcode' => 'DOC-BC-777',
    ]);

    expect($document->barcodeHistory()->first()->repository_id)->toBe($this->repo->id);
});

// 5 ---------------------------------------------------------------------------
it('does NOT record history when a document is created without a barcode', function () {
    $document = Document::withoutGlobalScopes()->create([
        'identifier' => 'DBT-005',
        'series_id' => $this->series->id,
        'batch_id' => $this->batch->id,
        'repository_id' => $this->repo->id,
    ]);

    expect($document->barcodeHistory()->count())->toBe(0);
});

// 6 --------------------------------------------------------------------------- (Fix 4)
it('allows multiple documents to share a NULL barcode', function () {
    $a = Document::withoutGlobalScopes()->create([
        'identifier' => 'DBT-NULL-1',
        'series_id' => $this->series->id,
        'batch_id' => $this->batch->id,
        'repository_id' => $this->repo->id,
        // no barcode → NULL
    ]);
    $b = Document::withoutGlobalScopes()->create([
        'identifier' => 'DBT-NULL-2',
        'series_id' => $this->series->id,
        'batch_id' => $this->batch->id,
        'repository_id' => $this->repo->id,
        // no barcode → NULL
    ]);

    expect($a->barcode)->toBeNull()
        ->and($b->barcode)->toBeNull()
        ->and(Document::withoutGlobalScopes()->whereNull('barcode')->count())->toBeGreaterThanOrEqual(2);
});

// 7 --------------------------------------------------------------------------- (Fix 4)
it('rejects two documents sharing the same non-null barcode (unique violation)', function () {
    Document::withoutGlobalScopes()->create([
        'identifier' => 'DBT-DUP-1',
        'series_id' => $this->series->id,
        'batch_id' => $this->batch->id,
        'repository_id' => $this->repo->id,
        'barcode' => 'DOC-DUP-BC',
    ]);

    expect(fn () => Document::withoutGlobalScopes()->create([
        'identifier' => 'DBT-DUP-2',
        'series_id' => $this->series->id,
        'batch_id' => $this->batch->id,
        'repository_id' => $this->repo->id,
        'barcode' => 'DOC-DUP-BC', // same non-null barcode → must fail
    ]))->toThrow(QueryException::class);
});
