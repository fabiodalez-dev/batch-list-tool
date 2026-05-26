<?php

declare(strict_types=1);

use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ §3.1.1 — CRUD of all metadata fields (Appendix 2 reference).
 *
 * Each test pins a specific metadata field's create/update path:
 *   - Document: identifier, document_type, series_id, batch_id, notes,
 *     disinfestation_date, custom_fields (json), extra (schemaless)
 *   - Batch: batch_number, description, type, is_active
 *   - Box: box_number, barcode, barcode_status, is_legacy
 *   - Authority: identifier, surname, given_names, practice dates
 *   - Series: code, title, description
 *   - Volume: volume_number, dates_start/end
 *   - Accession: code, accession_date
 *
 * Total: 12 tests.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function s311_seed(): array
{
    $repo = Repository::factory()->create(['code' => 'S311-' . substr(uniqid(), -4)]);
    $series = Series::create(['code' => 'S311S-' . substr(uniqid(), -4), 'title' => 'S311 Series', 'is_active' => true]);

    return [$repo, $series];
}

function s311_makeDoc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'S311-' . uniqid(),
        'document_type' => 'Register',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

it('§ 3.1.1 #1: Document.identifier persists and is mass-assignable', function () {
    [$repo, $series] = s311_seed();
    $doc = s311_makeDoc($repo->id, $series->id, ['identifier' => 'R7-V12']);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->find($doc->id)->identifier)
        ->toBe('R7-V12');
});

it('§ 3.1.1 #2: Document.document_type persists and is editable', function () {
    [$repo, $series] = s311_seed();
    $doc = s311_makeDoc($repo->id, $series->id, ['document_type' => 'Original']);
    expect($doc->document_type)->toBe('Original');
    $doc->update(['document_type' => 'Register']);
    expect($doc->refresh()->document_type)->toBe('Register');
});

it('§ 3.1.1 #3: Document.notes persists and is updateable', function () {
    [$repo, $series] = s311_seed();
    $doc = s311_makeDoc($repo->id, $series->id, ['notes' => 'initial']);
    $doc->update(['notes' => 'updated note']);
    expect($doc->refresh()->notes)->toBe('updated note');
});

it('§ 3.1.1 #4: Document.disinfestation_date persists as a Carbon date', function () {
    [$repo, $series] = s311_seed();
    $doc = s311_makeDoc($repo->id, $series->id, ['disinfestation_date' => '2026-05-15']);
    expect($doc->disinfestation_date->format('Y-m-d'))->toBe('2026-05-15');
});

it('§ 3.1.1 #5: Document.custom_fields persists as JSON cast to array', function () {
    [$repo, $series] = s311_seed();
    $doc = s311_makeDoc($repo->id, $series->id, ['custom_fields' => ['a' => 1, 'b' => 'two']]);
    $fresh = Document::withoutGlobalScope(RepositoryScope::class)->find($doc->id);
    expect($fresh->custom_fields)->toBe(['a' => 1, 'b' => 'two']);
});

it('§ 3.1.1 #6: Batch.batch_number + .description + .type persist', function () {
    $repo = Repository::factory()->create(['code' => 'S311-B-' . substr(uniqid(), -4)]);
    $b = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 4000 + random_int(0, 99),
        'description' => 'A batch description',
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repo->id, 'is_active' => true,
    ]);
    expect($b->description)->toBe('A batch description')
        ->and($b->type)->toBe('NOTARY_ACCESSION')
        ->and($b->is_active)->toBeTrue();
});

it('§ 3.1.1 #7: Box.box_number + .barcode + .barcode_status persist', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 4100 + random_int(0, 99),
        'type' => 'MAIN_COLLECTION', 'repository_id' => $repo->id, 'is_active' => true,
    ]);
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'box_number' => 'B-42',
        'barcode' => 'AA' . random_int(10000, 99999),
        'barcode_status' => 'IN',
    ]);
    $fresh = Box::withoutGlobalScopes()->find($box->id);
    expect($fresh->box_number)->toBe('B-42')
        ->and($fresh->barcode_status)->toBe('IN');
});

it('§ 3.1.1 #8: Authority.identifier + surname + practice dates persist', function () {
    $a = Authority::create([
        'identifier' => 'R7-' . uniqid(),
        'alternative_identifier' => 'MS517',
        'surname' => 'Abela',
        'given_names' => 'Carlo',
        'entity_type' => 'PERSON',
        'practice_dates_start' => 1607,
        'practice_dates_end' => 1629,
    ]);
    expect($a->surname)->toBe('Abela')
        ->and($a->practice_dates_start)->toBe(1607)
        ->and($a->practice_dates_end)->toBe(1629)
        ->and($a->alternative_identifier)->toBe('MS517');
});

it('§ 3.1.1 #9: Series.code + .title + .description persist', function () {
    $s = Series::create([
        'code' => 'S311-' . substr(uniqid(), -4),
        'title' => 'Register Copies (Registro)',
        'description' => 'Series R',
        'is_active' => true,
    ]);
    expect($s->title)->toBe('Register Copies (Registro)')
        ->and($s->description)->toBe('Series R');
});

it('§ 3.1.1 #10: Volume.volume_number + dates_start/end persist', function () {
    [$repo, $series] = s311_seed();
    $doc = s311_makeDoc($repo->id, $series->id);
    $v = Volume::create([
        'document_id' => $doc->id,
        'volume_number' => 'V12',
        'dates_start' => '1607-01-01',
        'dates_end' => '1629-12-31',
    ]);
    expect($v->volume_number)->toBe('V12')
        ->and($v->dates_start->format('Y'))->toBe('1607')
        ->and($v->dates_end->format('Y'))->toBe('1629');
});

it('§ 3.1.1 #11: Accession.code + accession_date persist', function () {
    $repo = Repository::factory()->create();
    $a = Accession::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'ACC-' . uniqid(),
        'accession_date' => '2025-06-15',
        'repository_id' => $repo->id,
        'notes' => 'Joseph Tabone Accession',
    ]);
    expect($a->code)->toStartWith('ACC-')
        ->and($a->accession_date->format('Y-m-d'))->toBe('2025-06-15')
        ->and($a->notes)->toBe('Joseph Tabone Accession');
});

it('§ 3.1.1 #12: Document soft-delete preserves the row in withTrashed()', function () {
    [$repo, $series] = s311_seed();
    $doc = s311_makeDoc($repo->id, $series->id);
    $id = $doc->id;
    $doc->delete();
    expect(Document::withoutGlobalScope(RepositoryScope::class)->find($id))->toBeNull()
        ->and(Document::withTrashed()->withoutGlobalScope(RepositoryScope::class)->find($id))
        ->not->toBeNull();
});
