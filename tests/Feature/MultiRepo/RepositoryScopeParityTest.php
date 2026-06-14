<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\ActiveRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/**
 * F2 — RepositoryScope (Documents/Batches, direct repository_id) must honour the
 * active-repository narrowing for privileged users, matching
 * ThroughBatchRepositoryScope (Boxes). Otherwise an admin who picks one repo in
 * the switcher sees narrowed Boxes but ALL Documents — an inconsistent view.
 */
it('narrows Documents AND Boxes consistently for an admin with an active repository', function (): void {
    actingAs(User::factory()->create()->assignRole('admin'));

    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $batchA = Batch::factory()->create(['repository_id' => $repoA->id]);
    $batchB = Batch::factory()->create(['repository_id' => $repoB->id]);
    $series = Series::factory()->create();

    Document::factory()->create(['repository_id' => $repoA->id, 'series_id' => $series->id]);
    Document::factory()->create(['repository_id' => $repoB->id, 'series_id' => $series->id]);
    Box::factory()->create(['batch_id' => $batchA->id, 'box_type' => 'RAS']);
    Box::factory()->create(['batch_id' => $batchB->id, 'box_type' => 'RAS']);

    resolve(ActiveRepository::class)->set($repoA->id);

    expect(Document::count())->toBe(1);
    expect(Box::count())->toBe(1);
});

it('shows everything for an admin with no active repository (All)', function (): void {
    actingAs(User::factory()->create()->assignRole('admin'));

    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $batchA = Batch::factory()->create(['repository_id' => $repoA->id]);
    $batchB = Batch::factory()->create(['repository_id' => $repoB->id]);
    $series = Series::factory()->create();

    Document::factory()->create(['repository_id' => $repoA->id, 'series_id' => $series->id]);
    Document::factory()->create(['repository_id' => $repoB->id, 'series_id' => $series->id]);
    Box::factory()->create(['batch_id' => $batchA->id, 'box_type' => 'RAS']);
    Box::factory()->create(['batch_id' => $batchB->id, 'box_type' => 'RAS']);

    resolve(ActiveRepository::class)->set(null);

    expect(Document::count())->toBe(2);
    expect(Box::count())->toBe(2);
});
