<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * NAF Feedback-1 comment #19 — a document must keep a history of its locations,
 * not just the current one. Document::locationHistory() logs every location_id
 * change with a snapshot of the breadcrumb label.
 */
uses(RefreshDatabase::class);

it('logs an entry on create and on each location change, snapshotting labels', function () {
    $repo = Repository::create(['code' => 'LH', 'name' => 'Loc History Repo']);
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id, 'name' => 'Charlene Ellul']);
    $u->assignRole('super_admin');
    $this->actingAs($u);

    $series = Series::create(['code' => 'REG', 'title' => 'Registers', 'repository_id' => $repo->id]);
    $room = Location::create(['name' => 'Room A', 'type' => 'room', 'repository_id' => $repo->id]);
    $shelf = Location::create(['name' => 'Shelf 1', 'type' => 'room', 'repository_id' => $repo->id]);

    // Create with an initial location → one "create" entry.
    $doc = Document::create([
        'identifier' => 'DOC-LH-1',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'location_id' => $room->id,
    ]);

    expect($doc->locationHistory()->count())->toBe(1);
    $first = $doc->locationHistory()->first();
    expect($first->from_location_id)->toBeNull()
        ->and($first->to_location_id)->toBe($room->id)
        ->and($first->to_location_label)->toContain('Room A')
        ->and((int) $first->changed_by_user_id)->toBe((int) $u->id)
        ->and($first->source)->toBe('create');

    // Move to a new location → a second "update" entry with from→to.
    $doc->update(['location_id' => $shelf->id]);

    expect($doc->locationHistory()->count())->toBe(2);
    $latest = $doc->locationHistory()->first(); // ordered by changed_at desc
    expect($latest->from_location_id)->toBe($room->id)
        ->and($latest->to_location_id)->toBe($shelf->id)
        ->and($latest->from_location_label)->toContain('Room A')
        ->and($latest->to_location_label)->toContain('Shelf 1')
        ->and($latest->source)->toBe('update');

    // A save that does not touch location_id adds nothing.
    $doc->update(['notes' => 'unrelated change']);
    expect($doc->locationHistory()->count())->toBe(2);
});
