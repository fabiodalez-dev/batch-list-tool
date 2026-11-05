<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Client 2026-11-05: how an authority reads, and what is kept when a document
 * changes volume or authority.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

dataset('authorised forms', [
    'her example' => [['surname' => 'Psaila', 'given_names' => 'Giuseppe Raimondo', 'practice_dates_start' => 1831, 'practice_dates_end' => 1878], 'Psaila, Giuseppe Raimondo, 1831-1878'],
    'every part' => [['surname' => 'Vella', 'maiden_surname' => 'Borg', 'given_names' => 'Maria', 'name_suffix' => 'Senior', 'practice_dates_start' => 1900, 'practice_dates_end' => 1910], 'Vella neè Borg, Maria, Senior, 1900-1910'],
    'practice and NTG span' => [['surname' => 'Mifsud', 'given_names' => 'Karm', 'practice_dates_start' => 1850, 'practice_dates_end' => 1860, 'ntg_dates_start' => 1840, 'ntg_dates_end' => 1870], 'Mifsud, Karm, 1840-1870'],
    'no dates' => [['surname' => 'Abela', 'given_names' => 'Nicola'], 'Abela, Nicola'],
    'start only' => [['surname' => 'Abela', 'given_names' => 'Nicola', 'practice_dates_start' => 1804], 'Abela, Nicola, 1804'],
]);

it('builds the authorised form of name from its parts', function (array $attributes, string $expected): void {
    expect((new Authority($attributes))->computedAuthorisedForm())->toBe($expected);
})->with('authorised forms');

it('labels an authority by citing code and authorised form', function (): void {
    expect((new Authority(['alternative_identifier' => 'R431', 'authorised_form_of_name' => 'Scaglia, Ascanio, 1700-1710', 'surname' => 'Other']))->citingLabel())
        ->toBe('R431: Scaglia, Ascanio, 1700-1710')
        ->and((new Authority(['alternative_identifier' => 'R5', 'surname' => 'Abela', 'given_names' => 'Nicola']))->citingLabel())
        ->toBe('R5: Abela, Nicola');
});

it('keeps past volumes and past authorities apart on the document', function (): void {
    $repo = Repository::factory()->create();
    $this->actingAs(bl_actor('super_admin', $repo));
    $document = Document::factory()->create(['repository_id' => $repo->id, 'volume_number' => '4']);
    $authority = Authority::query()->create(['alternative_identifier' => 'R900', 'surname' => 'Abela', 'given_names' => 'Nicola', 'entity_type' => 'Notary']);

    DocumentIdentifierHistory::recordPastVolume($document, '5', '4');
    DocumentIdentifierHistory::recordPastAuthority($document, $authority->id);

    expect($document->pastVolumes()->pluck('previous_volume')->all())->toBe(['5'])
        ->and($document->pastAuthorities()->pluck('previous_authority_id')->all())->toBe([$authority->id])
        ->and($document->pastAuthorities()->first()->previousAuthority->is($authority))->toBeTrue()
        ->and($document->pastVolumes()->first()->previous_identifier)->toBe($document->identifier);
});
