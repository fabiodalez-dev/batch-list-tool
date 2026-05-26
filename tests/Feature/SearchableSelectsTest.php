<?php

declare(strict_types=1);

use App\Filament\Support\SearchableSelects;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Coverage for App\Filament\Support\SearchableSelects.
 *
 * These tests call the static search-result closures directly. They
 * do NOT spin up the Livewire/Filament page because the closures are
 * pure functions of (search string, DB state) — testing them at this
 * level keeps the suite fast and lets us assert on the exact label
 * format the operator will see.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();

    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::findOrCreate($r, 'web');
    }

    $admin = User::factory()->create([
        'email' => 'sst-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin);
});

/**
 * Build a Repository + Series scaffold for the Document/Batch/Box tests.
 *
 * @return array{0: Repository, 1: Series}
 */
function sst_scaffold(): array
{
    $repo = Repository::factory()->create([
        'code' => 'SST_' . substr(uniqid(), -6),
    ]);
    $series = Series::firstOrCreate(
        ['code' => 'SST_' . substr(uniqid(), -4)],
        ['title' => 'SST series', 'is_active' => true],
    );

    return [$repo, $series];
}

function sst_makeBatch(int $repoId): Batch
{
    do {
        $n = random_int(2000, 8999);
    } while (in_array($n, [33, 34, 36], true)
        || Batch::withoutGlobalScope(RepositoryScope::class)
            ->where('batch_number', $n)->exists());

    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function sst_makeDoc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'SST-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

/* =============================================================================
 |  Document
 |============================================================================*/

it('Document search returns at most 50 results', function () {
    [$repo, $series] = sst_scaffold();

    // 60 documents, all with the same prefix so the search matches all of them.
    for ($i = 0; $i < 60; $i++) {
        sst_makeDoc($repo->id, $series->id, [
            'identifier' => 'CAPTEST-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        ]);
    }

    $results = SearchableSelects::documentSearchResults('CAPTEST-');

    expect($results)->toHaveCount(SearchableSelects::MAX_RESULTS);
});

it('Document search matches by identifier prefix', function () {
    [$repo, $series] = sst_scaffold();

    $r45 = sst_makeDoc($repo->id, $series->id, ['identifier' => 'R45']);
    $r450 = sst_makeDoc($repo->id, $series->id, ['identifier' => 'R450']);
    $r451 = sst_makeDoc($repo->id, $series->id, ['identifier' => 'R451']);
    $noise = sst_makeDoc($repo->id, $series->id, ['identifier' => 'OTHER-123']);

    $results = SearchableSelects::documentSearchResults('R45');

    expect(array_keys($results))->toContain($r45->id, $r450->id, $r451->id);
    expect(array_keys($results))->not->toContain($noise->id);
});

it('Document search matches by authority surname', function () {
    [$repo, $series] = sst_scaffold();

    $abela = Authority::create([
        'identifier' => 'A-ABELA-' . substr(uniqid(), -4),
        'surname' => 'Abela',
        'entity_type' => 'PERSON',
    ]);

    $doc = sst_makeDoc($repo->id, $series->id);
    $doc->authorities()->attach($abela->id, ['is_primary' => true]);

    $noise = sst_makeDoc($repo->id, $series->id);

    $results = SearchableSelects::documentSearchResults('Abela');

    expect(array_keys($results))->toContain($doc->id);
    expect(array_keys($results))->not->toContain($noise->id);
});

it('Document search matches by barcode_in', function () {
    [$repo, $series] = sst_scaffold();

    $hit = sst_makeDoc($repo->id, $series->id, [
        'identifier' => 'BC-HIT-' . substr(uniqid(), -4),
        'barcode_in' => 'AA40822',
    ]);
    $miss = sst_makeDoc($repo->id, $series->id, [
        'identifier' => 'BC-MISS-' . substr(uniqid(), -4),
        'barcode_in' => 'XX99999',
    ]);

    $results = SearchableSelects::documentSearchResults('AA40822');

    expect(array_keys($results))->toContain($hit->id);
    expect(array_keys($results))->not->toContain($miss->id);
});

it('Document label format is <identifier> — <surname> — bc:<barcode_in>', function () {
    [$repo, $series] = sst_scaffold();

    $a = Authority::create([
        'identifier' => 'A-LBL-' . substr(uniqid(), -4),
        'surname' => 'Buttigieg',
        'entity_type' => 'PERSON',
    ]);

    $doc = sst_makeDoc($repo->id, $series->id, [
        'identifier' => 'R110-LBL',
        'barcode_in' => 'AA1234',
    ]);
    $doc->authorities()->attach($a->id, ['is_primary' => true]);

    $label = SearchableSelects::documentLabel($doc->fresh()->load('authorities'));

    expect($label)->toBe('R110-LBL — Buttigieg — bc:AA1234');
});

it('Document label falls back to em-dashes for missing surname and barcode', function () {
    [$repo, $series] = sst_scaffold();

    $doc = sst_makeDoc($repo->id, $series->id, [
        'identifier' => 'EMPTY-LBL',
        'barcode_in' => null,
    ]);

    $label = SearchableSelects::documentLabel($doc->fresh()->load('authorities'));

    expect($label)->toBe('EMPTY-LBL — — — bc:—');
});

/* =============================================================================
 |  Box
 |============================================================================*/

it('Box label format is Batch X/Box Y — type — status', function () {
    [$repo, $_] = sst_scaffold();
    $batch = sst_makeBatch($repo->id);
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => '12',
        'batch_id' => $batch->id,
        'barcode' => 'BAR-12',
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ]);

    $label = SearchableSelects::boxLabel($box->fresh()->load('batch'));

    expect($label)->toBe("Batch {$batch->batch_number}/Box 12 — RAS — IN");
});

it('Box search matches by box_number or by batch number', function () {
    [$repo, $_] = sst_scaffold();
    $batch = sst_makeBatch($repo->id);

    $boxA = Box::create([
        'box_type' => 'RAS',
        'box_number' => 'BSEARCH-A',
        'batch_id' => $batch->id,
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ]);
    $boxB = Box::create([
        'box_type' => 'RAS',
        'box_number' => 'BSEARCH-B',
        'batch_id' => $batch->id,
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ]);

    $results = SearchableSelects::boxSearchResults('BSEARCH-A');

    expect(array_keys($results))->toContain($boxA->id);
    expect(array_keys($results))->not->toContain($boxB->id);
});

/* =============================================================================
 |  Authority
 |============================================================================*/

it('Authority search matches by identifier OR surname', function () {
    $a = Authority::create([
        'identifier' => 'R140',
        'alternative_identifier' => 'MS670',
        'surname' => 'Canciur',
        'given_names' => 'Antonio',
        'entity_type' => 'PERSON',
        'practice_dates_start' => 1499,
        'practice_dates_end' => 1531,
    ]);

    $byIdent = SearchableSelects::authoritySearchResults('R140');
    $bySurname = SearchableSelects::authoritySearchResults('Canciur');

    expect(array_keys($byIdent))->toContain($a->id);
    expect(array_keys($bySurname))->toContain($a->id);
});

it('Authority label includes practice dates when present', function () {
    $a = Authority::create([
        'identifier' => 'R110',
        'surname' => 'Buttigieg',
        'given_names' => 'Antonio',
        'entity_type' => 'PERSON',
        'practice_dates_start' => 1759,
        'practice_dates_end' => 1798,
    ]);

    $label = SearchableSelects::authorityLabel($a->fresh());

    expect($label)->toBe('R110 — Buttigieg Antonio (1759-1798)');
});

it('Authority label omits dates when both endpoints are null', function () {
    $a = Authority::create([
        'identifier' => 'R-NODATES',
        'surname' => 'NoDates',
        'entity_type' => 'PERSON',
    ]);

    $label = SearchableSelects::authorityLabel($a->fresh());

    expect($label)->toBe('R-NODATES — NoDates');
});

/* =============================================================================
 |  Soft-deleted records — "(deleted)" suffix
 |============================================================================*/

it('Authority option-label appends (deleted) for soft-deleted records', function () {
    $a = Authority::create([
        'identifier' => 'R-DEL',
        'surname' => 'Deleted',
        'entity_type' => 'PERSON',
    ]);
    $id = $a->id;
    $a->delete();

    // Drive the private getOptionLabelUsing closure via reflection on the
    // Select built by the factory.
    $select = SearchableSelects::authority('authority_id');
    $label = invokeOptionLabelUsing($select, $id);

    expect($label)->toBeString();
    expect($label)->toContain('(deleted)');
    expect($label)->toContain('R-DEL');
});

it('Document option-label appends (deleted) for soft-deleted records', function () {
    [$repo, $series] = sst_scaffold();

    $doc = sst_makeDoc($repo->id, $series->id, [
        'identifier' => 'R-DOC-DEL',
        'barcode_in' => 'DEL-BC',
    ]);
    $id = $doc->id;
    $doc->delete();

    $select = SearchableSelects::document('document_id');
    $label = invokeOptionLabelUsing($select, $id);

    expect($label)->toBeString();
    expect($label)->toContain('(deleted)');
    expect($label)->toContain('R-DOC-DEL');
});

/* =============================================================================
 |  Batch / Series
 |============================================================================*/

it('Batch label is "Batch <N> — <type>"', function () {
    [$repo, $_] = sst_scaffold();
    $batch = sst_makeBatch($repo->id);

    $label = SearchableSelects::batchLabel($batch->fresh());

    expect($label)->toBe("Batch {$batch->batch_number} — NOTARY_ACCESSION");
});

it('Series label is "<code> — <title>"', function () {
    $s = Series::create([
        'code' => 'RWL',
        'title' => 'Registers Private Practice Public Wills',
        'is_active' => true,
    ]);

    $label = SearchableSelects::seriesLabel($s->fresh());

    expect($label)->toBe('RWL — Registers Private Practice Public Wills');
});

/* =============================================================================
 |  Empty search → returns first N records ordered by their natural sort key
 |============================================================================*/

it('Empty search returns at most MAX_RESULTS records sorted by identifier asc', function () {
    [$repo, $series] = sst_scaffold();

    for ($i = 0; $i < 60; $i++) {
        sst_makeDoc($repo->id, $series->id, [
            'identifier' => 'EMPTY-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        ]);
    }

    $results = SearchableSelects::documentSearchResults('');

    expect($results)->toHaveCount(SearchableSelects::MAX_RESULTS);

    // Keys map to ids — but the values (labels) start with the identifier.
    // Asserting on the first label confirms ASC order by identifier.
    $firstLabel = array_values($results)[0];
    expect($firstLabel)->toStartWith('EMPTY-000 ');
});

/* =============================================================================
 |  Accession
 |============================================================================*/

it('Accession label includes batch number when relation is set', function () {
    [$repo, $_] = sst_scaffold();
    $batch = sst_makeBatch($repo->id);
    $acc = Accession::create([
        'code' => 'ACC-2026-01',
        'accession_date' => '2026-01-15',
        'batch_id' => $batch->id,
        'repository_id' => $repo->id,
    ]);

    $label = SearchableSelects::accessionLabel($acc->fresh()->load('batch'));

    expect($label)->toBe("ACC-2026-01 — batch {$batch->batch_number}");
});

/**
 * Drive the `getOptionLabelUsing` closure stored on a Filament Select.
 * Filament stores it as a protected property on \Filament\Forms\Components\Select;
 * we extract it via reflection and call it with the supplied $value so the
 * test does not need a full Livewire/Filament page render.
 */
function invokeOptionLabelUsing(Select $select, mixed $value): mixed
{
    $ref = new ReflectionClass(Select::class);
    $prop = $ref->getProperty('getOptionLabelUsing');
    $prop->setAccessible(true);
    $closure = $prop->getValue($select);

    if (! ($closure instanceof Closure)) {
        throw new RuntimeException('Select has no getOptionLabelUsing closure.');
    }

    return $closure($value);
}
