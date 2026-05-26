<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Imports\SeriesImporter;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Series;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Importer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ §3.1.3 — Bulk import (CSV/Excel) of new accessions.
 *
 * The five Importer classes form the bulk-import surface. These ten tests
 * pin the column declaration contracts, model bindings, and template
 * generation symmetry — the bits an integration test in BulkImportV2Test.php
 * does not.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('§ 3.1.3 #1: AuthorityImporter is bound to App\\Models\\Authority', function () {
    expect(AuthorityImporter::getModel())->toBe(Authority::class);
});

it('§ 3.1.3 #2: SeriesImporter is bound to App\\Models\\Series', function () {
    expect(SeriesImporter::getModel())->toBe(Series::class);
});

it('§ 3.1.3 #3: BatchImporter is bound to App\\Models\\Batch', function () {
    expect(BatchImporter::getModel())->toBe(Batch::class);
});

it('§ 3.1.3 #4: BoxImporter is bound to App\\Models\\Box', function () {
    expect(BoxImporter::getModel())->toBe(Box::class);
});

it('§ 3.1.3 #5: DocumentImporter is bound to App\\Models\\Document', function () {
    expect(DocumentImporter::getModel())->toBe(Document::class);
});

it('§ 3.1.3 #6: BatchImporter columns include batch_number, type, description, is_active, repository_code', function () {
    $cols = collect(BatchImporter::getColumns())->map(fn ($c) => $c->getName());
    expect($cols->all())->toContain('batch_number')
        ->and($cols->all())->toContain('type')
        ->and($cols->all())->toContain('description')
        ->and($cols->all())->toContain('is_active')
        ->and($cols->all())->toContain('repository_code');
});

it('§ 3.1.3 #7: BoxImporter columns include box_type, barcode_status, parent_box (FK by name)', function () {
    $cols = collect(BoxImporter::getColumns())->map(fn ($c) => $c->getName())->all();
    expect($cols)->toContain('box_type')
        ->and($cols)->toContain('barcode_status');
});

it('§ 3.1.3 #8: SeriesImporter columns include code, title, is_wills_series', function () {
    $cols = collect(SeriesImporter::getColumns())->map(fn ($c) => $c->getName())->all();
    expect($cols)->toContain('code')
        ->and($cols)->toContain('title')
        ->and($cols)->toContain('is_wills_series');
});

it('§ 3.1.3 #9: TemplateGenerator::TEMPLATES has all 5 entities (auth/series/batch/box/document)', function () {
    expect(TemplateGenerator::TEMPLATES)->toHaveKey('authority')
        ->and(TemplateGenerator::TEMPLATES)->toHaveKey('series')
        ->and(TemplateGenerator::TEMPLATES)->toHaveKey('batch')
        ->and(TemplateGenerator::TEMPLATES)->toHaveKey('box')
        ->and(TemplateGenerator::TEMPLATES)->toHaveKey('document');
});

it('§ 3.1.3 #10: TemplateGenerator::GENERATOR_VERSION is a semver-like string', function () {
    expect(TemplateGenerator::GENERATOR_VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
});
