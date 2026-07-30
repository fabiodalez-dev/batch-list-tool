<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * NAF (2026-07-30): location and disinfestation date are two-level. A document
 * inherits its box's value by default and can override it per-document (only a
 * few records differ - a different cycle, a different archive). This is a
 * read-only fallback (Document::effectiveLocation / effectiveDisinfestationDate)
 * so nothing is materialised and the box value never drifts.
 *
 * Box level stays authoritative for the disinfestation report and for how boxes
 * are sent to / brought back from disinfestation; the document override is the
 * exception on top.
 */
uses(RefreshDatabase::class);

/**
 * @param array<string, mixed> $boxAttrs
 * @param array<string, mixed> $docAttrs
 */
function eld_doc(array $boxAttrs = [], array $docAttrs = [], bool $withBox = true): Document
{
    $repo = Repository::factory()->create();
    $box = null;
    if ($withBox) {
        $box = Box::factory()->create(array_merge(['box_type' => 'RAS'], $boxAttrs));
    }

    return Document::factory()->create(array_merge([
        'repository_id' => $repo->id,
        'current_box_id' => $box?->id,
    ], $docAttrs));
}

// ─────────────────────────── effectiveLocation ───────────────────────────

it('returns the document own location when set', function () {
    $own = Location::factory()->create();
    $boxLoc = Location::factory()->create();
    $doc = eld_doc(['location_id' => $boxLoc->id], ['location_id' => $own->id]);

    expect($doc->effectiveLocation()?->id)->toBe($own->id)
        ->and($doc->locationIsInherited())->toBeFalse();
});

it('inherits the box location when the document has none', function () {
    $boxLoc = Location::factory()->create();
    $doc = eld_doc(['location_id' => $boxLoc->id], ['location_id' => null]);

    expect($doc->effectiveLocation()?->id)->toBe($boxLoc->id)
        ->and($doc->locationIsInherited())->toBeTrue();
});

it('returns null when neither the document nor its box has a location', function () {
    $doc = eld_doc(['location_id' => null], ['location_id' => null]);

    expect($doc->effectiveLocation())->toBeNull()
        ->and($doc->locationIsInherited())->toBeFalse();
});

it('returns null when the document has no box and no location', function () {
    $doc = eld_doc(docAttrs: ['location_id' => null], withBox: false);

    expect($doc->effectiveLocation())->toBeNull()
        ->and($doc->locationIsInherited())->toBeFalse();
});

it('returns its own location even without a box', function () {
    $own = Location::factory()->create();
    $doc = eld_doc(docAttrs: ['location_id' => $own->id], withBox: false);

    expect($doc->effectiveLocation()?->id)->toBe($own->id)
        ->and($doc->locationIsInherited())->toBeFalse();
});

it('lets the document location override the box location (Charlene: box in Archive X, doc in Archive Y)', function () {
    $archiveX = Location::factory()->create(['name' => 'Archive X']);
    $archiveY = Location::factory()->create(['name' => 'Archive Y']);
    $doc = eld_doc(['location_id' => $archiveX->id], ['location_id' => $archiveY->id]);

    expect($doc->effectiveLocation()?->name)->toBe('Archive Y')
        ->and($doc->currentBox->location?->name)->toBe('Archive X');
});

it('does not drift: moving the box location afterwards never changes an overridden document', function () {
    $own = Location::factory()->create();
    $boxLoc = Location::factory()->create();
    $newBoxLoc = Location::factory()->create();
    $doc = eld_doc(['location_id' => $boxLoc->id], ['location_id' => $own->id]);

    // Box relocates.
    $doc->currentBox->update(['location_id' => $newBoxLoc->id]);
    $doc->unsetRelation('currentBox');

    // The overridden document is unaffected.
    expect($doc->fresh()->effectiveLocation()?->id)->toBe($own->id);
});

it('inherits dynamically: a non-overridden document reflects the box new location', function () {
    $boxLoc = Location::factory()->create();
    $newBoxLoc = Location::factory()->create();
    $doc = eld_doc(['location_id' => $boxLoc->id], ['location_id' => null]);

    $doc->currentBox->update(['location_id' => $newBoxLoc->id]);
    $doc->unsetRelation('currentBox');

    expect($doc->fresh()->effectiveLocation()?->id)->toBe($newBoxLoc->id)
        ->and($doc->fresh()->locationIsInherited())->toBeTrue();
});

// ─────────────────────── effectiveDisinfestationDate ───────────────────────

it('returns the document own disinfestation date when set', function () {
    $doc = eld_doc(
        ['disinfestation_date' => '2024-03-01'],
        ['disinfestation_date' => '2024-09-15'],
    );

    expect($doc->effectiveDisinfestationDate()?->toDateString())->toBe('2024-09-15')
        ->and($doc->disinfestationDateIsInherited())->toBeFalse();
});

it('inherits the box disinfestation date when the document has none', function () {
    $doc = eld_doc(
        ['disinfestation_date' => '2024-03-01'],
        ['disinfestation_date' => null],
    );

    expect($doc->effectiveDisinfestationDate()?->toDateString())->toBe('2024-03-01')
        ->and($doc->disinfestationDateIsInherited())->toBeTrue();
});

it('returns null disinfestation when neither has a date', function () {
    $doc = eld_doc(['disinfestation_date' => null], ['disinfestation_date' => null]);

    expect($doc->effectiveDisinfestationDate())->toBeNull()
        ->and($doc->disinfestationDateIsInherited())->toBeFalse();
});

it('returns null disinfestation when the document has no box and no date', function () {
    $doc = eld_doc(docAttrs: ['disinfestation_date' => null], withBox: false);

    expect($doc->effectiveDisinfestationDate())->toBeNull()
        ->and($doc->disinfestationDateIsInherited())->toBeFalse();
});

it('lets a document in a different cycle keep its own disinfestation date over the box', function () {
    $doc = eld_doc(
        ['disinfestation_date' => '2024-01-10'],
        ['disinfestation_date' => '2023-06-20'],
    );

    // The document went through an earlier cycle than its box.
    expect($doc->effectiveDisinfestationDate()?->toDateString())->toBe('2023-06-20')
        ->and($doc->currentBox->disinfestation_date?->toDateString())->toBe('2024-01-10');
});

it('does not drift: changing the box date never changes an overridden document date', function () {
    $doc = eld_doc(
        ['disinfestation_date' => '2024-01-10'],
        ['disinfestation_date' => '2023-06-20'],
    );

    $doc->currentBox->update(['disinfestation_date' => '2025-01-01']);
    $doc->unsetRelation('currentBox');

    expect($doc->fresh()->effectiveDisinfestationDate()?->toDateString())->toBe('2023-06-20');
});

it('inherits dynamically: a non-overridden document reflects the box new date', function () {
    $doc = eld_doc(
        ['disinfestation_date' => '2024-01-10'],
        ['disinfestation_date' => null],
    );

    $doc->currentBox->update(['disinfestation_date' => '2025-02-02']);
    $doc->unsetRelation('currentBox');

    expect($doc->fresh()->effectiveDisinfestationDate()?->toDateString())->toBe('2025-02-02')
        ->and($doc->fresh()->disinfestationDateIsInherited())->toBeTrue();
});

// ─────────────────── independence of the two dimensions ───────────────────

it('resolves location and disinfestation independently (own location, inherited date)', function () {
    $own = Location::factory()->create();
    $boxLoc = Location::factory()->create();
    $doc = eld_doc(
        ['location_id' => $boxLoc->id, 'disinfestation_date' => '2024-04-04'],
        ['location_id' => $own->id, 'disinfestation_date' => null],
    );

    expect($doc->effectiveLocation()?->id)->toBe($own->id)
        ->and($doc->locationIsInherited())->toBeFalse()
        ->and($doc->effectiveDisinfestationDate()?->toDateString())->toBe('2024-04-04')
        ->and($doc->disinfestationDateIsInherited())->toBeTrue();
});

// ───────────────────────── template correctness ─────────────────────────

it('keeps the box-level location importable on the Boxes template', function () {
    $boxColumns = collect(BoxImporter::getColumns())->map(fn ($c) => $c->getName());
    expect($boxColumns)->toContain('location');
});

it('does NOT add a Location import column to the Documents template (override is a manual UI edit)', function () {
    // The Documents template is a fixed, position-matched legacy layout. The
    // document-level location override is set by hand on the form, so the
    // importer must not gain a stray `location` column (that would break the
    // header<->column round-trip and the legacy position match). Boxes DO
    // import a location; Documents do NOT.
    $boxColumns = collect(BoxImporter::getColumns())->map(fn ($c) => $c->getName());
    $docColumns = collect(DocumentImporter::getColumns())->map(fn ($c) => $c->getName());

    expect($boxColumns)->toContain('location')
        ->and($docColumns)->not->toContain('location');
});

it('still exposes legacy NRA / Museum location columns on the Documents template', function () {
    $docColumns = collect(DocumentImporter::getColumns())->map(fn ($c) => $c->getName());

    expect($docColumns)->toContain('nra_location')
        ->and($docColumns)->toContain('museum_location');
});
