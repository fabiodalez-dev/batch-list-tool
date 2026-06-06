<?php

declare(strict_types=1);

use App\Models\DocumentType;
use App\Support\BulkImport\EntityResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Wave D2 — DocumentType identifier field + EntityResolver::resolveDocumentType.
 */
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function wd2_dt(array $attrs = []): DocumentType
{
    return DocumentType::create(array_merge([
        'name' => 'DT-WD2-' . substr(uniqid(), -6),
        'is_active' => true,
    ], $attrs));
}

// ===========================================================================
// Schema + Model
// ===========================================================================

it('D2-Schema.1: document_types.identifier column exists', function (): void {
    expect(DB::getSchemaBuilder()->hasColumn('document_types', 'identifier'))->toBeTrue();
});

it('D2-Schema.2: identifier is nullable — type with no identifier persists', function (): void {
    $dt = wd2_dt(['identifier' => null]);
    expect($dt->identifier)->toBeNull();
});

it('D2-Schema.3: two document types can both have NULL identifier', function (): void {
    // The unique index allows multiple NULLs (NULL != NULL by ANSI SQL).
    $dt1 = wd2_dt(['name' => 'Type-NULL-A', 'identifier' => null]);
    $dt2 = wd2_dt(['name' => 'Type-NULL-B', 'identifier' => null]);

    expect($dt1->identifier)->toBeNull();
    expect($dt2->identifier)->toBeNull();

    // Both rows visible in DB — no unique-constraint violation.
    expect(DocumentType::whereNull('identifier')->count())->toBeGreaterThanOrEqual(2);
});

it('D2-Schema.4: unique constraint prevents two types with the same non-NULL identifier', function (): void {
    wd2_dt(['identifier' => 'REG']);

    expect(fn () => wd2_dt(['identifier' => 'REG']))
        ->toThrow(QueryException::class);
});

it('D2-Model.1: identifier persists through create/refresh cycle', function (): void {
    $dt = wd2_dt(['identifier' => 'ORIG-TEST']);
    $dt->refresh();
    expect($dt->identifier)->toBe('ORIG-TEST');
});

// ===========================================================================
// EntityResolver::resolveDocumentType
// ===========================================================================

it('D2-Resolver.1: resolveDocumentType returns null for null/blank input', function (): void {
    EntityResolver::flushMemo();
    expect(EntityResolver::resolveDocumentType(null))->toBeNull();
    expect(EntityResolver::resolveDocumentType(''))->toBeNull();
    expect(EntityResolver::resolveDocumentType('   '))->toBeNull();
});

it('D2-Resolver.2: resolveDocumentType matches by identifier (case-insensitive)', function (): void {
    EntityResolver::flushMemo();
    wd2_dt(['name' => 'Registers', 'identifier' => 'REG']);

    $result = EntityResolver::resolveDocumentType('reg');
    expect($result)->not->toBeNull();
    expect($result)->toHaveKey('document_type_id');

    $result2 = EntityResolver::resolveDocumentType('REG');
    expect($result2)->not->toBeNull();
    expect($result2['document_type_id'])->toBe($result['document_type_id']);
});

it('D2-Resolver.3: resolveDocumentType falls back to name when identifier misses', function (): void {
    EntityResolver::flushMemo();
    $dt = wd2_dt(['name' => 'Originals', 'identifier' => 'ORIG']);

    // Query by full name (no type has identifier == "Originals")
    $result = EntityResolver::resolveDocumentType('originals');
    expect($result)->not->toBeNull();
    expect($result['document_type_id'])->toBe($dt->id);
});

it('D2-Resolver.4: resolveDocumentType returns null when neither identifier nor name matches', function (): void {
    EntityResolver::flushMemo();
    expect(EntityResolver::resolveDocumentType('NonExistentType-XYZ'))->toBeNull();
});
