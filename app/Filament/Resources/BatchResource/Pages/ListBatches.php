<?php

namespace App\Filament\Resources\BatchResource\Pages;

use App\Filament\Imports\BatchImporter;
use App\Filament\Resources\BatchResource;
use App\Models\Batch;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use HayderHatem\FilamentExcelImport\Actions\FullImportAction;

class ListBatches extends ListRecords
{
    protected static string $resource = BatchResource::class;

    /**
     * Header actions for the Batches list page.
     *
     * The importer enforces RFQ App.1 #1 (batch numbers 33/34/36 are
     * reserved and refused) client-side via Laravel `not_in:` rules so the
     * operator sees a per-row error in the failed-rows export instead of a
     * cryptic SQL CHECK violation from MySQL.
     */
    protected function getHeaderActions(): array
    {
        return [
            FullImportAction::make()
                ->importer(BatchImporter::class)
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->chunkSize(500)
                ->maxRows(50000)
                ->streamingThreshold(10 * 1024 * 1024)
                ->visible(fn () => auth()->user()?->can('create', Batch::class) ?? false),

            Actions\CreateAction::make(),
        ];
    }
}
