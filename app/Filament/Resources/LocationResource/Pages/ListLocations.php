<?php

declare(strict_types=1);

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Imports\LocationImporter;
use App\Filament\Resources\LocationResource;
use App\Models\Location;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use HayderHatem\FilamentExcelImport\Actions\FullImportAction;

class ListLocations extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FullImportAction::make()
                ->importer(LocationImporter::class)
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->chunkSize(500)
                ->maxRows(50000)
                ->streamingThreshold(10 * 1024 * 1024)
                ->visible(fn () => auth()->user()?->can('create', Location::class) ?? false),

            // Blank xlsx with the canonical Location import columns
            // (name, type, parent_name, repository_code, code, notes,
            // sort_order, is_active). Gated on the create policy.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('location'))
                ->visible(fn () => auth()->user()?->can('create', Location::class) ?? false),

            Actions\CreateAction::make()
                ->label('New Location'),   // Bug #15
        ];
    }
}
