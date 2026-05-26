<?php

namespace App\Filament\Resources\AuthorityResource\Pages;

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Resources\AuthorityResource;
use App\Models\Authority;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use HayderHatem\FilamentExcelImport\Actions\FullImportAction;

class ListAuthorities extends ListRecords
{
    protected static string $resource = AuthorityResource::class;

    /**
     * Header actions for the Authorities list page.
     *
     * The Import button uses {@see FullImportAction} from
     * `hayderhatem/filament-excel-import` — a drop-in replacement for
     * Filament's native `ImportAction` that adds:
     *   - .xlsx / .xls sheet support (PhpSpreadsheet under the hood),
     *   - sheet selector when the workbook has multiple sheets,
     *   - per-column dropdown mapping inside the modal,
     *   - automatic streaming for files larger than `streamingThreshold`.
     *
     * Visibility is gated through {@see Authority} policy
     * (`create_authority` permission). A user without create rights cannot
     * trigger the action UI — defence-in-depth on top of the per-tenant
     * scope which would refuse the inserts anyway.
     */
    protected function getHeaderActions(): array
    {
        return [
            FullImportAction::make()
                ->importer(AuthorityImporter::class)
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->chunkSize(500)
                ->maxRows(50000)
                // 10 MB threshold flips to streaming mode automatically; the
                // sample file is 808 rows (≪ MB) so the in-memory path is
                // used by default — fast for that size.
                ->streamingThreshold(10 * 1024 * 1024)
                ->visible(fn () => auth()->user()?->can('create', Authority::class) ?? false),

            // Blank xlsx whose row-1 headers match Authorities_Sample.xlsx
            // verbatim. Gated on the create policy so a viewer cannot
            // probe the schema by downloading the template.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('authority'))
                ->visible(fn () => auth()->user()?->can('create', Authority::class) ?? false),

            Actions\CreateAction::make(),
        ];
    }
}
