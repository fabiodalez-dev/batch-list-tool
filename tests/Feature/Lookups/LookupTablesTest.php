<?php

use App\Models\Lookup\BarcodeStatus;
use App\Models\Lookup\BatchType;
use App\Models\Lookup\BoxType;
use App\Models\Lookup\CurrentBoxType;
use App\Models\Lookup\DigitisationStatus;
use App\Models\Lookup\FlagType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds barcode statuses', function () {
    expect(BarcodeStatus::pluck('code')->all())->toEqualCanonicalizing(['IN', 'OUT', 'PERM_OUT']);
});

it('seeds box types incl. legacy flags', function () {
    expect(BoxType::pluck('code')->all())->toEqualCanonicalizing(['RAS', 'IN_SITU', 'NRA', 'MAV', 'STVC']);
    expect(BoxType::where('code', 'MAV')->value('is_legacy'))->toBeTrue();
    expect(BoxType::where('code', 'RAS')->value('is_legacy'))->toBeFalse();
});

it('seeds the 15 flag types with colours mapped', function () {
    expect(FlagType::count())->toBe(15);
    expect(FlagType::where('code', 'barcode_issue')->value('colour'))->toBe('brown');
    expect(FlagType::where('code', 'entry_issue')->value('colour'))->toBe('pink');
});

it('seeds digitisation + current box types + batch types', function () {
    expect(DigitisationStatus::pluck('code')->all())->toEqualCanonicalizing(['VHMML', 'NRA', 'none']);
    expect(CurrentBoxType::pluck('code')->all())->toEqualCanonicalizing(['RAS Box', 'Big Brown Box', 'Small Brown Box']);
    expect(CurrentBoxType::where('code', 'Big Brown Box')->value('counts_as'))->toBe(2);
    expect(BatchType::pluck('code')->all())->toEqualCanonicalizing(['MAIN_COLLECTION', 'NOTARY_ACCESSION']);
});

it('active() scope orders by sort_order and filters inactive', function () {
    BarcodeStatus::where('code', 'OUT')->update(['is_active' => false]);
    expect(BarcodeStatus::active()->pluck('code')->all())->not->toContain('OUT');
});
