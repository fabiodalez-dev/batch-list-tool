<?php

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes the contract custody states', function () {
    expect(Document::CUSTODY_STATUSES)->toBe(['in_box', 'not_in_box', 'mounted_no_box']);
});

it('persists a custody status', function () {
    $doc = Document::factory()->create(['custody_status' => 'mounted_no_box']);
    expect($doc->fresh()->custody_status)->toBe('mounted_no_box');
});

it('defaults custody status to in_box', function () {
    $doc = Document::factory()->create();
    expect($doc->fresh()->custody_status)->toBe('in_box');
});
