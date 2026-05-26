<?php

declare(strict_types=1);

use App\Console\Commands\LinkCreatorTextToAuthorities;
use App\Models\Authority;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Security Baseline — F-001 short-token guard + F-009 ambiguous handling
 *
 * Two non-negotiable safety rules for nra:link-creator-text-to-authorities:
 *
 *   F-001 — never fuzzy-match a surname token shorter than 4 characters
 *           (avoids false-positives like "Foo" → "Fontana").
 *   F-009 — on duplicate-surname collisions, SKIP and log the candidate count
 *           so the operator can resolve manually. NEVER pick arbitrarily.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->repository = Repository::factory()->create([
        'code' => 'CRL_' . substr(uniqid(), -6),
    ]);
    $this->series = Series::query()->first()
        ?? Series::create(['code' => 'CRL_TEST', 'title' => 'Test', 'is_active' => true]);
});

test('F-009 — ambiguous surname does NOT create a document_authority row and IS logged', function () {
    // Create 3 Authorities sharing the surname "Zenobia" — exact-match path
    // resolves to >1 candidate → must be marked ambiguous and skipped.
    $surname = 'Zenobia' . substr(uniqid(), -4);
    $a1 = Authority::create([
        'identifier' => 'ZX' . uniqid('', false) . '1', 'surname' => $surname,
        'entity_type' => 'PERSON',
    ]);
    $a2 = Authority::create([
        'identifier' => 'ZX' . uniqid('', false) . '2', 'surname' => $surname,
        'entity_type' => 'PERSON',
    ]);
    $a3 = Authority::create([
        'identifier' => 'ZX' . uniqid('', false) . '3', 'surname' => $surname,
        'entity_type' => 'PERSON',
    ]);

    // Document with that ambiguous surname stored in extra.legacy_creator_text
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'CRL-AMB-' . uniqid(),
        'document_type' => 'TEST',
        'series_id' => $this->series->id,
        'repository_id' => $this->repository->id,
        'extra' => ['legacy_creator_text' => $surname],
    ]);

    // Run the command — it MUST NOT pick any of the 3 candidates.
    $exit = Artisan::call('nra:link-creator-text-to-authorities');
    expect($exit)->toBe(0);

    // No pivot row was created for this Document
    $pivotCount = DB::table('document_authority')
        ->where('document_id', $doc->id)
        ->count();
    expect($pivotCount)->toBe(0);

    // The match log records the ambiguous skip per F-009 specification.
    $doc->refresh();
    $log = $doc->extra['creator_match_log'] ?? null;
    expect($log)->toBeArray();
    expect($log)->toContain("{$surname} → ambiguous_3_candidates");
});

test('F-001 — fuzzy match on a token shorter than 4 chars returns null', function () {
    // Even if a longer surname containing the short token exists, F-001 forbids
    // the fuzzy LIKE expansion (would otherwise falsely link "Foo" → "Fontana").
    Authority::create([
        'identifier' => 'FT_' . uniqid('', false),
        'surname' => 'Fontana',
        'entity_type' => 'PERSON',
    ]);

    // Invoke the private resolveAuthority() via reflection — this is the
    // narrowest possible test of the F-001 guard. We pass a token whose final
    // word ("Foo") is 3 characters → must return null.
    $command = app(LinkCreatorTextToAuthorities::class);
    $authoritiesBySurname = Authority::query()
        ->whereNotNull('surname')
        ->where('surname', '!=', '')
        ->get(['id', 'surname'])
        ->groupBy(fn ($a) => mb_strtolower(trim($a->surname)));

    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('resolveAuthority');
    $method->setAccessible(true);

    // Short token (3 chars) → must be REFUSED (null) even though "Fontana" is a
    // LIKE-match for "Foo". This is the F-001 contract.
    $result = $method->invoke($command, 'Foo', $authoritiesBySurname);
    expect($result)->toBeNull();

    // Sanity contrast: a 4+ char token IS allowed to fuzzy-match.
    $resultLong = $method->invoke($command, 'Font', $authoritiesBySurname);
    expect($resultLong)->toBeArray();
    expect($resultLong['method'] ?? null)->toBe('fuzzy');
});
