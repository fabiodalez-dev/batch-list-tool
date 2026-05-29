<?php

declare(strict_types=1);

/**
 * RFQ §3.2.1 — omni-search coverage for extra fields.
 *
 * Pins the behaviour that museum_reference, object_reference_number, tracking
 * (direct document columns) and location (relation via location_id) are all
 * reachable via the applyOmniSearch() path on the documents list page.
 *
 * The fields are searched via OMNI_DIRECT_COLUMNS (direct LIKE) for the three
 * document columns, and via orWhereHas('location', ...) for the location
 * relation (mirrors the pattern already used for series/batch/box/accession).
 */

use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();

    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $this->admin = User::factory()->create([
        'email' => 'omniextra-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $this->admin->assignRole('super_admin');

    $this->repo = Repository::factory()->create([
        'code' => 'OMREX_' . substr(uniqid(), -6),
    ]);

    $this->series = Series::firstOrCreate(
        ['code' => 'OMREX_S_' . substr(uniqid(), -4)],
        ['title' => 'Extra Fields Test Series', 'is_active' => true],
    );
});

// ---------------------------------------------------------------------------
// Helper: create a document scoped to the test repo + series.
// ---------------------------------------------------------------------------

function extra_makeDoc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScopes()->create(array_merge([
        'identifier' => 'EX-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

// ---------------------------------------------------------------------------
// museum_reference
// ---------------------------------------------------------------------------

test('omni-search finds a document by museum_reference', function (): void {
    $this->actingAs($this->admin);

    $hit = extra_makeDoc($this->repo->id, $this->series->id, ['museum_reference' => 'KANTILENA-XYZ']);
    $miss = extra_makeDoc($this->repo->id, $this->series->id, ['museum_reference' => 'something-else']);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'KANTILENA-XYZ')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

// ---------------------------------------------------------------------------
// object_reference_number
// ---------------------------------------------------------------------------

test('omni-search finds a document by object_reference_number', function (): void {
    $this->actingAs($this->admin);

    $hit = extra_makeDoc($this->repo->id, $this->series->id, ['object_reference_number' => 'ORN-999']);
    $miss = extra_makeDoc($this->repo->id, $this->series->id, ['object_reference_number' => 'ORN-000']);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'ORN-999')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

// ---------------------------------------------------------------------------
// tracking
// ---------------------------------------------------------------------------

test('omni-search finds a document by tracking', function (): void {
    $this->actingAs($this->admin);

    $hit = extra_makeDoc($this->repo->id, $this->series->id, ['tracking' => 'TRK-555']);
    $miss = extra_makeDoc($this->repo->id, $this->series->id, ['tracking' => 'TRK-000']);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'TRK-555')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

// ---------------------------------------------------------------------------
// location (relation — searched via orWhereHas('location', ...))
// ---------------------------------------------------------------------------

test('omni-search finds a document by location name', function (): void {
    $this->actingAs($this->admin);

    $locHit = Location::withoutGlobalScopes()->create([
        'name' => 'Museum Showcase Alpha',
        'code' => 'LC-ALPHA-' . strtoupper(substr(uniqid(), -6)),
        'type' => 'showcase',
        'repository_id' => $this->repo->id,
        'depth' => 0,
        'is_active' => true,
    ]);
    $locMiss = Location::withoutGlobalScopes()->create([
        'name' => 'Conservation Room Beta',
        'code' => 'LC-BETA-' . strtoupper(substr(uniqid(), -6)),
        'type' => 'room',
        'repository_id' => $this->repo->id,
        'depth' => 0,
        'is_active' => true,
    ]);

    $hit = extra_makeDoc($this->repo->id, $this->series->id, [
        'identifier' => 'LOC-ALPHA-HIT',
        'location_id' => $locHit->id,
    ]);
    $miss = extra_makeDoc($this->repo->id, $this->series->id, [
        'identifier' => 'LOC-BETA-MISS',
        'location_id' => $locMiss->id,
    ]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'Museum Showcase Alpha')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

test('omni-search finds a document by location code', function (): void {
    $this->actingAs($this->admin);

    $loc = Location::withoutGlobalScopes()->create([
        'name' => 'Some Room',
        'code' => 'VAULT-UNIQUE-' . strtoupper(substr(uniqid(), -6)),
        'type' => 'room',
        'repository_id' => $this->repo->id,
        'depth' => 0,
        'is_active' => true,
    ]);

    $hit = extra_makeDoc($this->repo->id, $this->series->id, [
        'identifier' => 'LOC-CODE-HIT',
        'location_id' => $loc->id,
    ]);
    $miss = extra_makeDoc($this->repo->id, $this->series->id, [
        'identifier' => 'LOC-CODE-MISS',
    ]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', $loc->code)
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});
