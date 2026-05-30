<?php

declare(strict_types=1);

use App\Models\Location;
use Database\Seeders\NraLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Feedback1 Wave C1.5 — NraLocationSeeder creates the dictated NRA locations
 * (St Chris – Archive 1/2, St Paul – Cataloguing, RAS) idempotently.
 */
uses(RefreshDatabase::class);

it('creates the four NRA locations', function () {
    (new NraLocationSeeder)->run();

    foreach (array_keys(NraLocationSeeder::LOCATIONS) as $name) {
        expect(Location::where('name', $name)->whereNull('repository_id')->exists())
            ->toBeTrue("missing location: {$name}");
    }

    expect(Location::whereIn('name', array_keys(NraLocationSeeder::LOCATIONS))->count())->toBe(4);
});

it('is idempotent — running twice creates no duplicates', function () {
    (new NraLocationSeeder)->run();
    (new NraLocationSeeder)->run();

    expect(Location::whereIn('name', array_keys(NraLocationSeeder::LOCATIONS))->count())->toBe(4);

    foreach (array_keys(NraLocationSeeder::LOCATIONS) as $name) {
        expect(Location::where('name', $name)->whereNull('repository_id')->count())->toBe(1);
    }
});

it('does not replace a pre-existing location row', function () {
    // Pre-seed one of the target names with a custom type/notes.
    $existing = Location::create([
        'name' => 'RAS',
        'type' => 'temp_holding',
        'notes' => 'do not touch',
        'is_active' => true,
    ]);

    (new NraLocationSeeder)->run();

    $existing->refresh();
    expect($existing->type)->toBe('temp_holding')
        ->and($existing->notes)->toBe('do not touch')
        ->and(Location::where('name', 'RAS')->count())->toBe(1);
});
