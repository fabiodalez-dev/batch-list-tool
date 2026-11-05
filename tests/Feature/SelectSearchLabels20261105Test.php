<?php

declare(strict_types=1);

use App\Filament\Support\SearchableSelects;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Client 2026-11-05: "difficult to find the box (can't find it)". The selects
 * show "Batch 2" and "R611: Muscat, Elizabeth Amalia"; typing what they show
 * has to find it.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create();
    $this->actingAs(bl_actor('super_admin', $this->repo));
});

it('finds a batch typed as its option reads', function (): void {
    $two = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '2']);
    $nine = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '9']);

    $found = SearchableSelects::batchSearchResults('Batch 2');

    expect($found)->toHaveKey($two->id)->not->toHaveKey($nine->id);
});

it('finds an authority typed as its option reads', function (string $typed): void {
    $wanted = Authority::query()->create(['alternative_identifier' => 'R611', 'surname' => 'Muscat', 'given_names' => 'Elizabeth', 'authorised_form_of_name' => 'Muscat, Elizabeth']);
    $other = Authority::query()->create(['alternative_identifier' => 'R61', 'surname' => 'Abela', 'given_names' => 'Antonio', 'authorised_form_of_name' => 'Abela, Antonio']);

    $found = SearchableSelects::authoritySearchResults($typed);

    expect($found)->toHaveKey($wanted->id)->not->toHaveKey($other->id);
})->with(['code and colon' => 'R611:', 'whole label' => 'R611: Muscat, Elizabeth']);

it('still searches an authority by surname or by part of its code', function (): void {
    $wanted = Authority::query()->create(['alternative_identifier' => 'R611', 'surname' => 'Muscat', 'given_names' => 'Elizabeth', 'authorised_form_of_name' => 'Muscat, Elizabeth']);

    expect(SearchableSelects::authoritySearchResults('Muscat'))->toHaveKey($wanted->id)
        ->and(SearchableSelects::authoritySearchResults('R61'))->toHaveKey($wanted->id);
});
