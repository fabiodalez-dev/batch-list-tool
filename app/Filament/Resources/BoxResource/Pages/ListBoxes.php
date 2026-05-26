<?php

namespace App\Filament\Resources\BoxResource\Pages;

use App\Filament\Imports\BoxImporter;
use App\Filament\Resources\BoxResource;
use App\Models\Box;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use HayderHatem\FilamentExcelImport\Actions\FullImportAction;

class ListBoxes extends ListRecords
{
    protected static string $resource = BoxResource::class;

    /**
     * Header actions for the Boxes list page.
     *
     * RFQ rules enforced by the importer:
     *  - #3 IN_SITU / NRA boxes require a parent RAS box (rejected with a
     *    per-row validation error otherwise).
     *  - #4 MAV / STVC types force `is_legacy = true` on save.
     *  - #5 PERM_OUT requires `disinfestation_date` (validation error
     *    otherwise — MySQL would also refuse the insert, but we want a
     *    clean message for the operator).
     */
    protected function getHeaderActions(): array
    {
        return [
            FullImportAction::make()
                ->importer(BoxImporter::class)
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->chunkSize(500)
                ->maxRows(50000)
                ->streamingThreshold(10 * 1024 * 1024)
                ->visible(fn () => auth()->user()?->can('create', Box::class) ?? false),

            Actions\CreateAction::make(),
        ];
    }
}
