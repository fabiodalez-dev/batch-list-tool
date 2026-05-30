<?php

use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

it('records an audit row when a box location changes', function () {
    config(['audit.console' => true]); // owen-it skips console (CLI/test) by default
    $r = Repository::factory()->create();
    $b = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 3, 'repository_id' => $r->id, 'type' => 'MAIN_COLLECTION']);
    $loc1 = Location::factory()->create();
    $loc2 = Location::factory()->create();
    $box = Box::create(['box_type' => 'RAS', 'box_number' => 1, 'batch_id' => $b->id, 'location_id' => $loc1->id]);
    $box->update(['location_id' => $loc2->id]);
    $audit = Audit::where('auditable_type', Box::class)->where('auditable_id', $box->id)
        ->where('event', 'updated')
        ->get()->first(fn ($a) => array_key_exists('location_id', (array) ($a->new_values ?? [])));
    expect($audit)->not->toBeNull();
    expect((int) $audit->new_values['location_id'])->toBe($loc2->id);
    expect((int) ($audit->old_values['location_id'] ?? 0))->toBe($loc1->id);
});
