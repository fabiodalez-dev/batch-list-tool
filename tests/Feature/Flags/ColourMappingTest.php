<?php

use App\Models\DocumentFlag;

it('maps all six contract colours to a flag type', function () {
    expect(DocumentFlag::COLOUR_TYPES)->toBe([
        'pink' => 'entry_issue',
        'brown' => 'barcode_issue',
        'orange' => 'location_check',
        'grey' => 'not_disinfested_onsite',
        'red' => 'mould_treatment',
        'yellow' => 'fragment_sorted',
    ]);
});

it('registers every mapped colour type in TYPES', function () {
    foreach (DocumentFlag::COLOUR_TYPES as $type) {
        expect(in_array($type, DocumentFlag::TYPES, true))->toBeTrue("missing {$type}");
    }
});
