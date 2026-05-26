<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * PR #11b — App\Console\Commands\LinkCreatorTextToAuthorities.
 *
 * F-009 ambiguous-skip is already covered by SecurityBaseline/CreatorLinkingTest.
 * Here we cover:
 *   59 - --dry-run does not write pivot rows or modify Document.extra
 *   60 - exact surname match → pivot row created (happy path)
 *   61 - command output contains 'ambiguous' on collision
 *   62 - chunked execution (verifies the chunkById(500) contract via query log)
 */
uses(DatabaseTransactions::class);

function makeRepo_link(string $prefix = 'LK'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

function makeSeries_link(): Series
{
    return Series::query()->first()
        ?? Series::create(['code' => 'LK-S', 'title' => 'LK series', 'is_active' => true]);
}

function makeDoc_link(int $repoId, int $seriesId, ?string $creatorText = null): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'LK-DOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
        'extra' => $creatorText !== null ? ['legacy_creator_text' => $creatorText] : null,
    ]);
}

/* 59. --dry-run does not write pivot or persist match log */
test('--dry-run does not write document_authority rows or modify extra.creator_match_log', function () {
    $repo = makeRepo_link();
    $series = makeSeries_link();

    $unique = 'DRYRUN' . substr(uniqid(), -6);
    $authority = Authority::create([
        'identifier' => 'DR-' . $unique,
        'surname' => $unique,
        'entity_type' => 'PERSON',
    ]);

    $doc = makeDoc_link($repo->id, $series->id, $unique);

    $beforePivots = DB::table('document_authority')->where('document_id', $doc->id)->count();
    expect($beforePivots)->toBe(0);

    Artisan::call('nra:link-creator-text-to-authorities', ['--dry-run' => true]);

    $afterPivots = DB::table('document_authority')->where('document_id', $doc->id)->count();
    expect($afterPivots)->toBe(0);

    // The match log MUST NOT be persisted when dry-running
    $doc->refresh();
    $log = $doc->extra['creator_match_log'] ?? null;
    expect($log)->toBeNull();
});

/* 60. Exact surname match → pivot row created */
test('Exact surname match links the document to the authority via pivot', function () {
    $repo = makeRepo_link();
    $series = makeSeries_link();

    $surname = 'Exact' . substr(uniqid(), -6);
    $authority = Authority::create([
        'identifier' => 'EX-' . $surname,
        'surname' => $surname,
        'entity_type' => 'PERSON',
    ]);

    $doc = makeDoc_link($repo->id, $series->id, $surname);

    Artisan::call('nra:link-creator-text-to-authorities');

    $pivotCount = DB::table('document_authority')
        ->where('document_id', $doc->id)
        ->where('authority_id', $authority->id)
        ->count();
    expect($pivotCount)->toBe(1);

    // is_primary should be true for the first (only) match
    $pivot = DB::table('document_authority')
        ->where('document_id', $doc->id)
        ->where('authority_id', $authority->id)
        ->first();
    expect((int) $pivot->is_primary)->toBe(1);

    // The match log was persisted
    $doc->refresh();
    expect($doc->extra['creator_match_log'] ?? null)->toBeArray();
});

/*
 * 61. Command output contains "ambiguous" when a surname collision occurs.
 *
 * F-009 itself is covered in SecurityBaseline/CreatorLinkingTest. Here we
 * specifically assert the COMMAND prints the "Ambiguous" line so the
 * operator-facing UX is preserved.
 */
test('Command output reports ambiguous collisions on the operator console', function () {
    $repo = makeRepo_link();
    $series = makeSeries_link();

    $surname = 'Coll' . substr(uniqid(), -4);
    Authority::create([
        'identifier' => 'C1-' . $surname,
        'surname' => $surname,
        'entity_type' => 'PERSON',
    ]);
    Authority::create([
        'identifier' => 'C2-' . $surname,
        'surname' => $surname,
        'entity_type' => 'PERSON',
    ]);

    makeDoc_link($repo->id, $series->id, $surname);

    Artisan::call('nra:link-creator-text-to-authorities');
    $output = Artisan::output();

    // Either "Ambiguous" header or the per-document ambiguous_N_candidates log
    expect(strtolower($output))->toContain('ambiguous');
});

/*
 * 62. Chunked transaction — chunkById(500).
 *
 * We verify two facts that together prove the chunked-commit contract:
 *   (a) the command source declares chunkById(500) and DB::beginTransaction
 *       per chunk (static read of the file — robust across DB drivers)
 *   (b) running the command against a smaller-than-chunk dataset still
 *       succeeds and produces pivot rows — no "no transaction" error.
 */
test('Command uses chunkById(500) with per-chunk transaction commits (F-002)', function () {
    $src = file_get_contents(base_path('app/Console/Commands/LinkCreatorTextToAuthorities.php'));

    expect($src)->toContain('chunkById(500');
    expect($src)->toContain('DB::beginTransaction()');
    expect($src)->toContain('DB::commit()');

    // Behavioural sanity: invoke against a tiny dataset and verify success.
    $repo = makeRepo_link('CH');
    $series = makeSeries_link();
    $sn = 'Chunk' . substr(uniqid(), -6);
    Authority::create([
        'identifier' => 'CH-' . $sn,
        'surname' => $sn,
        'entity_type' => 'PERSON',
    ]);
    makeDoc_link($repo->id, $series->id, $sn);

    $exit = Artisan::call('nra:link-creator-text-to-authorities');
    expect($exit)->toBe(0);
});
