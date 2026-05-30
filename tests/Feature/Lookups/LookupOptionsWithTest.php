<?php

declare(strict_types=1);

use App\Models\Lookup\BarcodeStatus;
use App\Models\Lookup\BoxType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * C4 — optionsWith($current) must include the record's CURRENT value even when
 * that lookup row has since been deactivated, so an edit form never drops a
 * stored-but-inactive value (which would make saving other fields blank it).
 */
it('optionsWith includes an inactive current value (BarcodeStatus)', function () {
    BarcodeStatus::where('code', 'OUT')->update(['is_active' => false]);

    $active = BarcodeStatus::options();
    expect($active)->not->toHaveKey('OUT');

    $withCurrent = BarcodeStatus::optionsWith('OUT');
    expect($withCurrent)->toHaveKey('OUT')
        ->and($withCurrent['OUT'])->toContain('inactive');
});

it('optionsWith is a no-op for an already-active or null value (BoxType)', function () {
    $active = BoxType::options();

    expect(BoxType::optionsWith('RAS'))->toBe($active)
        ->and(BoxType::optionsWith(null))->toBe($active)
        ->and(BoxType::optionsWith(''))->toBe($active);
});

it('optionsWith falls back to the raw code when the row is gone', function () {
    $withMissing = BarcodeStatus::optionsWith('GHOST_CODE');
    expect($withMissing)->toHaveKey('GHOST_CODE')
        ->and($withMissing['GHOST_CODE'])->toBe('GHOST_CODE');
});
