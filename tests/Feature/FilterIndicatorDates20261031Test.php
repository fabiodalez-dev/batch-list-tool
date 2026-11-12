<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\BoxMovementHistoryReport;
use App\Filament\Support\ListRules;
use App\Models\Repository;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Client 2026-10-30, issue 11: dates dd-mm-yyyy throughout the system. The
 * chips of an active filter still read "Oct 1, 2026" (rules) or the raw
 * "2026-10-01" (date-range filters).
 */
uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

dataset('date operators', [
    'after' => ['isAfter', 'is after 05-03-2026'],
    'before' => ['isBefore', 'is before 05-03-2026'],
    'on' => ['isDate', 'is 05-03-2026'],
]);

it('summarises a date rule as dd-mm-yyyy', function (string $operator, string $expected): void {
    $constraint = ListRules::date('updated_at', 'Updated at');
    $summary = $constraint->getOperator($operator)
        ->constraint($constraint)
        ->settings(['date' => '2026-03-05'])
        ->getSummary();

    expect($summary)->toContain($expected)->not->toContain('Mar 5, 2026');
})->with('date operators');

it('summarises a relative date rule as dd-mm-yyyy', function (): void {
    $this->travelTo('2026-10-31 10:00:00');
    $constraint = ListRules::date('updated_at', 'Updated at');
    $summary = $constraint->getOperator('isAfter')
        ->constraint($constraint)
        ->settings(['mode' => 'relative', 'preset' => 'today'])
        ->getSummary();

    expect($summary)->toContain('31-10-2026')->not->toContain('Oct 31, 2026');
});

it('keeps every date operator of the rule, in Filament\'s order', function (): void {
    $names = array_keys(ListRules::date('updated_at')->getOperators());

    expect($names)->toBe(array_keys(DateConstraint::make('updated_at')->nullable()->getOperators()));
});

it('shows a date-range filter chip as dd-mm-yyyy', function (): void {
    $repo = Repository::factory()->create();
    $this->actingAs(bl_actor('super_admin', $repo));

    Livewire::test(BoxMovementHistoryReport::class)
        ->filterTable('date_range', ['from' => '2026-03-05', 'to' => '2026-04-07'])
        ->assertSee('From: 05-03-2026')
        ->assertSee('To: 07-04-2026')
        ->filterTable('movement_date_range', ['from' => '2026-03-05'])
        ->assertSee('≥ 05-03-2026')
        ->assertDontSee('2026-03-05');
});
