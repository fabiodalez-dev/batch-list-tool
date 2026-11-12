<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource\Pages\EditAccession;
use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Filament\Resources\AccessionResource\Pages\ViewAccession;
use App\Filament\Resources\PracticeResource\Pages\ListPractices;
use App\Models\Accession;
use App\Models\Practice;
use App\Models\Repository;
use App\Support\DisplayDate;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Client 2026-10-30, issue 11: "Can you also check that dates are in the
 * format of dd-mm-yyyy throughout the whole system?"
 */
uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

function df_accession(): Accession
{
    $repo = Repository::factory()->create();
    test()->actingAs(bl_actor('super_admin', $repo));

    $accession = Accession::factory()->create([
        'repository_id' => $repo->id,
        'accession_date' => '2026-03-05',
        'accession_number' => '2026-001',
    ]);
    $accession->forceFill(['created_at' => Carbon::parse('2026-01-02 14:30:00')])->saveQuietly();

    return $accession;
}

it('shows a date column in a list as dd-mm-yyyy', function (): void {
    $accession = df_accession();

    Livewire::test(ListAccessions::class)
        ->assertTableColumnFormattedStateSet('accession_date', '05-03-2026', $accession);
});

it('shows a date and time column as dd-mm-yyyy HH:mm, also where a format was hard-coded', function (): void {
    $repo = Repository::factory()->create();
    $this->actingAs(bl_actor('super_admin', $repo));
    $practice = Practice::query()->create(['name' => 'DF practice', 'is_active' => true]);
    $practice->forceFill(['updated_at' => Carbon::parse('2026-01-02 14:30:00')])->saveQuietly();

    Livewire::test(ListPractices::class)
        ->assertTableColumnFormattedStateSet('updated_at', '02-01-2026 14:30', $practice);
});

it('shows dates on a view page as dd-mm-yyyy', function (): void {
    $accession = df_accession();

    Livewire::test(ViewAccession::class, ['record' => $accession->getRouteKey()])
        ->assertSee('05-03-2026')
        ->assertSee('02-01-2026 14:30')
        ->assertDontSee('Mar 5, 2026');
});

it('makes every date picker show dd-mm-yyyy whatever the browser language', function (): void {
    $date = DatePicker::make('d');
    $dateTime = DateTimePicker::make('dt')->seconds(false);

    expect($date->isNative())->toBeFalse()
        ->and($date->getDisplayFormat())->toBe('d-m-Y')
        ->and($dateTime->getDisplayFormat())->toBe('d-m-Y H:i')
        // Storage is untouched: the picker still hands Y-m-d to the model.
        ->and($date->getFormat())->toBe('Y-m-d');
});

it('still saves a date typed in a form as Y-m-d', function (): void {
    $accession = df_accession();

    Livewire::test(EditAccession::class, ['record' => $accession->getRouteKey()])
        ->assertSchemaStateSet(['accession_date' => '2026-03-05'])
        ->fillForm(['accession_date' => '2026-12-31'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($accession->fresh()->accession_date?->format('Y-m-d'))->toBe('2026-12-31')
        ->and(DisplayDate::date($accession->fresh()->accession_date))->toBe('31-12-2026');
});
