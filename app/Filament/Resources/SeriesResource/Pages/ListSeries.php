<?php

namespace App\Filament\Resources\SeriesResource\Pages;

use App\Filament\Imports\SeriesImporter;
use App\Filament\Resources\SeriesResource;
use App\Models\Series;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use HayderHatem\FilamentExcelImport\Actions\FullImportAction;

class ListSeries extends ListRecords
{
    protected static string $resource = SeriesResource::class;

    /**
     * Header actions for the Series list page.
     *
     * Series rows are reference data (29 rows in the sample), but the
     * "what is a wills series?" question (is_wills_series) drives
     * downstream validation rules (RFQ App.1 #2 — wills must land in
     * batch 50). The importer derives `is_wills_series` heuristically when
     * the column is not mapped — see {@see SeriesImporter::afterFill()}.
     */
    protected function getHeaderActions(): array
    {
        return [
            FullImportAction::make()
                ->importer(SeriesImporter::class)
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->chunkSize(500)
                ->maxRows(50000)
                ->streamingThreshold(10 * 1024 * 1024)
                ->visible(fn () => auth()->user()?->can('create', Series::class) ?? false),

            Actions\CreateAction::make(),
        ];
    }
}
