<?php

declare(strict_types=1);

use App\Support\BulkImport\SpreadsheetParsers;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Reusable: SpreadsheetParsers static helpers.
 *
 * Pin date / year-range / int / bool / entity-type parsing for the bulk
 * import row-shaping layer.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('SpreadsheetParsers: parseYearRange("1607-1629") yields [1607, 1629]', function () {
    expect(SpreadsheetParsers::parseYearRange('1607-1629'))->toBe([1607, 1629]);
});

it('SpreadsheetParsers: parseYearRange("1607") yields equal start/end years', function () {
    expect(SpreadsheetParsers::parseYearRange('1607'))->toBe([1607, 1607]);
});

it('SpreadsheetParsers: parseYearRange handles en-dash and em-dash separators', function () {
    expect(SpreadsheetParsers::parseYearRange('1500–1600'))->toBe([1500, 1600]);
    expect(SpreadsheetParsers::parseYearRange('1500—1600'))->toBe([1500, 1600]);
});

it('SpreadsheetParsers: parseYearRange("garbage") returns [null, null]', function () {
    expect(SpreadsheetParsers::parseYearRange('not a year'))->toBe([null, null]);
});

it('SpreadsheetParsers: parseDate("2026-05-26") returns Y-m-d', function () {
    expect(SpreadsheetParsers::parseDate('2026-05-26'))->toBe('2026-05-26');
});

it('SpreadsheetParsers: parseDate(null) returns null', function () {
    expect(SpreadsheetParsers::parseDate(null))->toBeNull();
});

it('SpreadsheetParsers: parseInt("42") returns 42', function () {
    expect(SpreadsheetParsers::parseInt('42'))->toBe(42);
});

it('SpreadsheetParsers: parseInt("1.0 Old Storage") returns 1', function () {
    expect(SpreadsheetParsers::parseInt('1.0 Old Storage'))->toBe(1);
});

it('SpreadsheetParsers: parseInt("") returns null', function () {
    expect(SpreadsheetParsers::parseInt(''))->toBeNull();
});

it('SpreadsheetParsers: parseBool accepts locale-mixed truthy set', function () {
    foreach (['1', 'yes', 'Y', 'true', 't', 'si', 'sì'] as $v) {
        expect(SpreadsheetParsers::parseBool($v))->toBeTrue("Value: {$v}");
    }
});
