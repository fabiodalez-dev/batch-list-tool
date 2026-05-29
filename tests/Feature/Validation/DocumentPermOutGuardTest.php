<?php

declare(strict_types=1);

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('rejects PERM_OUT without a disinfestation date', function () {
    Document::factory()->create([
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => null,
    ]);
})->throws(ValidationException::class);

it('allows PERM_OUT once a disinfestation date is set', function () {
    $doc = Document::factory()->create([
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => now(),
    ]);
    expect($doc->fresh()->barcode_status)->toBe('PERM_OUT');
});

it('allows a normal IN document with no disinfestation date', function () {
    $doc = Document::factory()->create([
        'barcode_status' => 'IN',
        'disinfestation_date' => null,
    ]);
    expect($doc->fresh()->barcode_status)->toBe('IN');
});
