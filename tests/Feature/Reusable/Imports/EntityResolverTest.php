<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Support\BulkImport\EntityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Reusable: EntityResolver contract.
 *
 * Pins the four resolution strategies for Authority (identifier, surname+given,
 * surname exact, fuzzy) plus Series, Batch, Box, Repository resolvers.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
    EntityResolver::flushMemo();
});

it('EntityResolver: resolveAuthority by exact identifier returns authority_id with method=identifier', function () {
    $a = Authority::create([
        'identifier' => 'R-IDENT-' . uniqid(),
        'surname' => 'Verdi',
        'entity_type' => 'PERSON',
    ]);
    $res = EntityResolver::resolveAuthority($a->identifier);
    expect($res)->not->toBeNull()
        ->and($res['authority_id'])->toBe($a->id)
        ->and($res['method'])->toBe('identifier');
});

it('EntityResolver: resolveAuthority by surname+given returns surname_given method', function () {
    $a = Authority::create([
        'identifier' => 'R-SG-' . uniqid(),
        'surname' => 'Rossi',
        'given_names' => 'Mario',
        'entity_type' => 'PERSON',
    ]);
    $res = EntityResolver::resolveAuthority(null, 'Rossi', 'Mario');
    expect($res)->not->toBeNull()
        ->and($res['authority_id'])->toBe($a->id)
        ->and($res['method'])->toBe('surname_given');
});

it('EntityResolver: resolveAuthority by surname exact returns surname_exact method', function () {
    $a = Authority::create([
        'identifier' => 'R-EX-' . uniqid(),
        'surname' => 'UniqueSurname' . uniqid(),
        'entity_type' => 'PERSON',
    ]);
    $res = EntityResolver::resolveAuthority(null, $a->surname);
    expect($res)->not->toBeNull()
        ->and($res['authority_id'])->toBe($a->id)
        ->and($res['method'])->toBe('surname_exact');
});

it('EntityResolver: resolveAuthority surname ambiguity returns ambiguous_count (F-009)', function () {
    $surname = 'Ambig' . substr(uniqid(), -6);
    Authority::create(['identifier' => 'R-AM1-' . uniqid(), 'surname' => $surname, 'entity_type' => 'PERSON']);
    Authority::create(['identifier' => 'R-AM2-' . uniqid(), 'surname' => $surname, 'entity_type' => 'PERSON']);

    $res = EntityResolver::resolveAuthority(null, $surname);
    expect($res)->toHaveKey('ambiguous_count')
        ->and($res['ambiguous_count'])->toBe(2)
        ->and($res['candidates'])->toBeArray()
        ->and(count($res['candidates']))->toBe(2);
});

it('EntityResolver: resolveAuthority refuses fuzzy match on tokens <4 chars (F-001)', function () {
    // No exact match exists for "Mai" — fuzzy would match anyway, but the
    // resolver refuses on <4 char tokens.
    Authority::create(['identifier' => 'R-F-' . uniqid(), 'surname' => 'Maillet', 'entity_type' => 'PERSON']);
    $res = EntityResolver::resolveAuthority(null, 'Mai');
    expect($res)->toBeNull();
});

it('EntityResolver: resolveAuthority fuzzy match works on 4+ char tokens with unique candidate', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Fuzzy match path uses CHAR_LENGTH(); MySQL only.');
    }
    Authority::create(['identifier' => 'R-FZ-' . uniqid(), 'surname' => 'Frangipanini' . uniqid(), 'entity_type' => 'PERSON']);
    $res = EntityResolver::resolveAuthority(null, 'Frangi');
    expect($res)->not->toBeNull()
        ->and($res)->toHaveKey('method')
        ->and($res['method'])->toBe('surname_fuzzy');
});

it('EntityResolver: resolveAuthority returns null when nothing matches (short token, no fuzzy)', function () {
    // Use a 3-char token so the fuzzy strategy is short-circuited (avoids
    // CHAR_LENGTH on SQLite). The resolver still walks identifier/exact paths.
    $res = EntityResolver::resolveAuthority(null, 'XYZ');
    expect($res)->toBeNull();
});

it('EntityResolver: resolveSeries by exact code returns series_id', function () {
    $s = Series::create([
        'code' => 'XS' . substr(uniqid(), -3),
        'title' => 'X Series',
        'is_active' => true,
    ]);
    $res = EntityResolver::resolveSeries($s->code);
    expect($res)->not->toBeNull()
        ->and($res['series_id'])->toBe($s->id);
});

it('EntityResolver: resolveSeries handles "CODE: Title" format', function () {
    $code = 'YS' . substr(uniqid(), -3);
    $s = Series::create(['code' => $code, 'title' => 'Y Title', 'is_active' => true]);
    $res = EntityResolver::resolveSeries("{$code}: Y Series Full Title");
    expect($res)->not->toBeNull()
        ->and($res['series_id'])->toBe($s->id);
});

it('EntityResolver: resolveSeries falls back to title match', function () {
    $title = 'Unique Series Title ' . uniqid();
    $s = Series::create([
        'code' => 'ZS' . substr(uniqid(), -3),
        'title' => $title, 'is_active' => true,
    ]);
    $res = EntityResolver::resolveSeries($title);
    expect($res)->not->toBeNull()
        ->and($res['series_id'])->toBe($s->id);
});

it('EntityResolver: resolveBatch returns forbidden marker for 34/36; batch 33 is reserved (valid — returns null when not found)', function () {
    // 34 and 36 are forbidden per RFQ Appendix 2.
    foreach ([34, 36] as $n) {
        $res = EntityResolver::resolveBatch($n);
        expect($res)->toHaveKey('forbidden')
            ->and($res['forbidden'])->toBe($n);
    }
    // 33 is reserved for old MAV boxes — NOT forbidden.
    expect(EntityResolver::resolveBatch(33))->toBeNull();
});

it('EntityResolver: resolveBatch by number returns batch_id', function () {
    $repo = Repository::factory()->create(['code' => 'ER-' . substr(uniqid(), -4)]);
    $n = 7000 + random_int(0, 999);
    $b = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $res = EntityResolver::resolveBatch($n);
    expect($res)->not->toBeNull()
        ->and($res['batch_id'])->toBe($b->id)
        ->and($res['batch_number'])->toBe($n);
});

it('EntityResolver: resolveBox by barcode returns box_id', function () {
    $batch = Batch::factory()->create();
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'barcode' => 'BAR-' . uniqid(),
    ]);
    $res = EntityResolver::resolveBox($box->barcode);
    expect($res)->not->toBeNull()
        ->and($res['box_id'])->toBe($box->id);
});

it('EntityResolver: resolveBox by (batch_id, box_number) returns box_id', function () {
    $batch = Batch::factory()->create();
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'box_number' => 'BN-' . uniqid(),
        'barcode' => null,
    ]);
    $res = EntityResolver::resolveBox(null, $batch->id, $box->box_number);
    expect($res)->not->toBeNull()
        ->and($res['box_id'])->toBe($box->id);
});

it('EntityResolver: resolveRepository by code returns repository_id', function () {
    $r = Repository::factory()->create(['code' => 'CODE-' . strtoupper(substr(uniqid(), -4))]);
    $res = EntityResolver::resolveRepository($r->code);
    expect($res)->not->toBeNull()
        ->and($res['repository_id'])->toBe($r->id);
});
