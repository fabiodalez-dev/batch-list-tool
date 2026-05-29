<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Reusable: Pest custom expectations + datasets.
 *
 * These tests double as "documentation by example" for callers of the
 * shared toBeForbiddenBatch / toBeWillsSeries helpers and the validBatchRange
 * dataset.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('Pest dataset: validBatchRange — accepts 1..29 main collection', function (int $n) {
    expect($n)->toBeLessThanOrEqual(Batch::MAIN_COLLECTION_MAX)
        ->and(in_array($n, Batch::FORBIDDEN_NUMBERS, true))->toBeFalse();
})->with([1, 7, 15, 28, 29]);

it('Pest dataset: notaryAccessionRange — 30+ excluding 34/36/50 (33 is reserved/valid)', function (int $n) {
    expect($n)->toBeGreaterThanOrEqual(30)
        ->and(in_array($n, Batch::FORBIDDEN_NUMBERS, true))->toBeFalse()
        ->and($n)->not->toBe(Batch::WILLS_BATCH);
})->with([30, 31, 32, 33, 35, 37, 38, 49, 51, 99]);

it('Pest dataset: forbiddenBatchNumbers all flag isForbidden() true', function (int $n) {
    $b = new Batch(['batch_number' => $n]);
    expect($b->isForbidden())->toBeTrue();
})->with([34, 36]);

it('Pest dataset: batch 33 is reserved (isReservedMav) but NOT forbidden', function () {
    $b = new Batch(['batch_number' => 33]);
    expect($b->isForbidden())->toBeFalse()
        ->and($b->isReservedMav())->toBeTrue();
});

it('Pest dataset: validSeriesCodes — recognised codes', function (string $code) {
    expect(in_array($code, ['R', 'REG', 'RWL', 'OWL', 'O'], true))->toBeTrue();
})->with(['R', 'REG', 'RWL', 'OWL', 'O']);

it('Pest expectation: Series::is_wills_series flag toggles correctly', function () {
    $s = Series::create([
        'code' => 'PE-' . substr(uniqid(), -4),
        'title' => 'PE Wills',
        'is_wills_series' => true,
        'is_active' => true,
    ]);
    expect($s->is_wills_series)->toBeTrue();
});

it('Pest expectation: Batch helpers behave as predicates', function () {
    $b = new Batch(['batch_number' => 50]);
    expect($b->isWillsOnly())->toBeTrue()
        ->and($b->isForbidden())->toBeFalse();
});
