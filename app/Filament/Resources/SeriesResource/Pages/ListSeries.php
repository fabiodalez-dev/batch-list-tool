<?php

namespace App\Filament\Resources\SeriesResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Imports\SeriesImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\SeriesResource;
use App\Models\Series;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSeries extends ListRecords
{
    use ExplainsPage;

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
            Actions\Action::make('import')
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                // One import path. The wizard orders parents ahead of
                // children, offers the overwrite choice and warns about
                // missing structural columns; the in-page modal did none
                // of those, so the same sheet behaved differently
                // depending on where it was uploaded from.
                ->url(ImportWizard::getUrl(['type' => 'series']))
                // Also gated on the wizard's own access check, so the
                // button is never shown to someone it would 403.
                ->visible(fn (): bool => ImportWizard::canAccess()
                    && (auth()->user()?->can('create', Series::class) ?? false)),

            // Blank xlsx whose row-1 headers match Series_Sample.xlsx
            // (the first 6 populated columns — trailing NULLs in the
            // sample are stripped). Gated on the create policy.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('series'))
                ->visible(fn () => auth()->user()?->can('create', Series::class) ?? false),

            Actions\CreateAction::make()
                ->label('New Subseries'),   // Bug #20; Client 2026-08-31 rename
        ];
    }
}
