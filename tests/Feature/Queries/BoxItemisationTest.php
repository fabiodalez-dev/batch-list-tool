<?php

declare(strict_types=1);

use App\Models\Document;
use App\Support\BoxItemisation;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Q5 (NAF Queries) — expand a "71 folders" record into an itemised list, either
 * as N placeholders or from a pasted/uploaded list of lines.
 */
uses(RefreshDatabase::class);

it('itemises a document into N sequential placeholder items', function (): void {
    $doc = Document::factory()->create();

    $created = BoxItemisation::itemiseCount($doc, 71, 'Folder');

    expect($created)->toBe(71)
        ->and($doc->items()->count())->toBe(71)
        ->and($doc->items()->min('position'))->toBe(1)
        ->and($doc->items()->max('position'))->toBe(71)
        ->and($doc->items()->orderBy('position')->first()->reference)->toBe('Folder 1');
});

it('itemises from a pasted list of lines, splitting reference and description', function (): void {
    $doc = Document::factory()->create();

    $created = BoxItemisation::itemiseFromLines($doc, [
        'F-1 | Deed of sale',
        'F-2',
        '   ',            // blank line skipped
        "F-3\tWill",      // tab separator
    ]);

    expect($created)->toBe(3);

    $items = $doc->items()->orderBy('position')->get();
    expect($items->pluck('reference')->all())->toBe(['F-1', 'F-2', 'F-3'])
        ->and($items[0]->description)->toBe('Deed of sale')
        ->and($items[1]->description)->toBeNull()
        ->and($items[2]->description)->toBe('Will');
});

it('appends by default and can replace when asked', function (): void {
    $doc = Document::factory()->create();

    BoxItemisation::itemiseCount($doc, 2);
    BoxItemisation::itemiseCount($doc, 2);      // appended
    expect($doc->items()->count())->toBe(4)
        ->and($doc->items()->max('position'))->toBe(4);

    BoxItemisation::itemiseFromLines($doc, ['only-one'], replace: true);
    expect($doc->items()->count())->toBe(1)
        ->and($doc->items()->first()->position)->toBe(1)
        ->and($doc->items()->first()->reference)->toBe('only-one');
});

it('creates nothing for a non-positive count or all-blank lines', function (): void {
    $doc = Document::factory()->create();

    expect(BoxItemisation::itemiseCount($doc, 0))->toBe(0)
        ->and(BoxItemisation::itemiseFromLines($doc, ['', '   ']))->toBe(0)
        ->and($doc->items()->count())->toBe(0);
});
