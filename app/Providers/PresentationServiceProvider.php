<?php

namespace App\Providers;

use App\Support\DisplayDate;
use App\Support\UserTablePreferences;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;

use function Livewire\on;

/**
 * How lists and dates look to the user, set once for the whole panel.
 */
class PresentationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->bootDateFormats();
        $this->bootTablePreferences();
    }

    /**
     * Client 2026-10-30: "Can you also check that dates are in the format of
     * dd-mm-yyyy throughout the whole system?" Filament's defaults were
     * "Oct 30, 2026" in lists and view pages, and the browser's own
     * date input showed whatever the PC's locale said (mm/dd/yyyy on a US
     * one). Only the display changes: pickers still hand Y-m-d to the model.
     */
    private function bootDateFormats(): void
    {
        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat(DisplayDate::DATE)
            ->defaultDateTimeDisplayFormat(DisplayDate::DATE_TIME));

        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultDateDisplayFormat(DisplayDate::DATE)
            ->defaultDateTimeDisplayFormat(DisplayDate::DATE_TIME));

        // Filament's own picker, not the browser's: only it can be told to
        // show day-month-year whatever the PC's language.
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker
            ->native(false)
            ->defaultDateDisplayFormat(DisplayDate::DATE)
            ->defaultDateTimeDisplayFormat(DisplayDate::DATE_TIME)
            ->defaultDateTimeWithSecondsDisplayFormat(DisplayDate::DATE_TIME_SECONDS));

        DatePicker::configureUsing(fn (DatePicker $picker) => $picker->closeOnDateSelection());
    }

    /**
     * Client 2026-10-30: column order and hidden columns kept per user
     * across logins. See UserTablePreferences.
     */
    private function bootTablePreferences(): void
    {
        // A layout that cannot be loaded or saved (the table not migrated
        // yet mid-deploy, two tabs saving at once) must never break a page:
        // the list still works with the session copy.
        on('pre-mount', function (): void {
            try {
                UserTablePreferences::seedSession();
            } catch (\Throwable $e) {
                Log::warning('Could not load saved table layouts: ' . $e->getMessage());
            }
        });

        on('dehydrate', function (Component $component): void {
            try {
                UserTablePreferences::persist($component);
            } catch (\Throwable $e) {
                Log::warning('Could not save a table layout: ' . $e->getMessage());
            }
        });
    }
}
