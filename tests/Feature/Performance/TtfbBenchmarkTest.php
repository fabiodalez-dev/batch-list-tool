<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/*
 * RFQ-2026-06 §3.4.1 — Time-to-first-byte target on a realistic dataset.
 *
 * The submission committed to "sub-second response times on a 3,000-doc
 * dataset" (FILLED PDF §3.4). The morning audit flagged the absence of
 * an explicit benchmark; this file pins one.
 *
 * What we measure
 *   - Wall-clock time around the Filament-equivalent paginated query that
 *     drives the Document list page (the hot path operators hit most).
 *   - We DON'T measure the full HTTP round-trip here: that requires
 *     Browser/Dusk + an HTTP server, which is Tier B #104 work. This
 *     gives a tight upper bound on the DB+ORM contribution to TTFB,
 *     which is the dominant cost on shared cPanel.
 *
 * Why a threshold of 500 ms
 *   - The submission's spec is 1000 ms TTFB at the 95th percentile.
 *     We set the test bar at 500 ms so the test fires a regression
 *     alarm with headroom — anything in [500, 1000] still ships,
 *     but the failing test is the operator's signal to investigate.
 *
 * Why SQLite-in-memory underestimates prod
 *   - SQLite in-memory is faster than cPanel MySQL on simple queries.
 *     The threshold is therefore CONSERVATIVE on dev hardware; if it
 *     fails here, it will definitely fail on prod. Real prod TTFB
 *     benchmarking lives in `tests/Browser/` once Dusk lands.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();

    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

function ttfb_seedDataset(int $documentCount): User
{
    $repo = Repository::factory()->create(['code' => 'TTFB-' . substr(uniqid(), -4)]);
    $series = Series::create([
        'code' => 'TTS-' . substr(uniqid(), -4),
        'title' => 'TTFB series',
        'is_active' => true,
    ]);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => random_int(7000, 8999),
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => 'TTB-' . substr(uniqid(), -6),
        'batch_id' => $batch->id,
        'barcode_status' => 'IN',
    ]);

    $authority = Authority::create([
        'identifier' => 'R-TTFB-' . substr(uniqid(), -4),
        'surname' => 'TTFB',
        'entity_type' => 'PERSON',
    ]);

    // Bulk insert is ~50× faster than factory create() for 3K rows.
    $rows = [];
    $now = now();
    for ($i = 0; $i < $documentCount; $i++) {
        $rows[] = [
            'identifier' => 'TTFB-' . $i,
            'document_type' => 'Register',
            'series_id' => $series->id,
            'repository_id' => $repo->id,
            'batch_id' => $batch->id,
            'current_box_id' => $box->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        Document::withoutGlobalScope(RepositoryScope::class)->insert($chunk);
    }

    return User::factory()->create([
        'email' => 'ttfb+' . uniqid() . '@test.local',
        'default_repository_id' => $repo->id,
        'is_active' => true,
    ])->assignRole('super_admin') ?? User::query()->latest('id')->first();
}

test('paginated Document list query returns under 500 ms on a 3000-doc dataset', function () {
    $user = ttfb_seedDataset(3000);
    $this->actingAs($user);

    // Warm the connection + opcache the relevant classes — first hit is slower.
    Document::withoutGlobalScope(RepositoryScope::class)->limit(1)->get();

    $start = microtime(true);
    $rows = Document::withoutGlobalScope(RepositoryScope::class)
        ->with(['series:id,code', 'batch:id,batch_number', 'currentBox:id,box_number,barcode_status'])
        ->orderBy('identifier')
        ->paginate(25);
    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($rows->total())->toBe(3000)
        ->and($rows->count())->toBe(25)
        ->and($elapsedMs)->toBeLessThan(
            500.0,
            "Paginated list query took {$elapsedMs}ms on 3000 docs — RFQ §3.4.1 threshold breached.",
        );
});

test('paginated query stays under 500 ms even with omni-search needle', function () {
    $user = ttfb_seedDataset(3000);
    $this->actingAs($user);

    Document::withoutGlobalScope(RepositoryScope::class)->limit(1)->get();

    $start = microtime(true);
    $rows = Document::withoutGlobalScope(RepositoryScope::class)
        ->with(['series:id,code', 'batch:id,batch_number'])
        ->where('identifier', 'like', '%TTFB-12%')
        ->orderBy('identifier')
        ->paginate(25);
    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($rows->count())->toBeGreaterThan(0)
        ->and($elapsedMs)->toBeLessThan(
            500.0,
            "Filtered list query took {$elapsedMs}ms on 3000 docs — RFQ §3.4.1 threshold breached.",
        );
});
