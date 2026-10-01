<?php

declare(strict_types=1);

namespace App\Filament\Resources\BatchResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Concerns\ExportsLikeTheTemplate;
use App\Filament\Imports\BatchImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\BatchResource;
use App\Models\Batch;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBatches extends ListRecords
{
    use ExplainsPage;
    use ExportsLikeTheTemplate;

    protected static string $resource = BatchResource::class;

    protected static function exportEntity(): string
    {
        return 'batch';
    }

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
            Actions\Action::make('import')
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                // One import path. The wizard orders parents ahead of
                // children, offers the overwrite choice and warns about
                // missing structural columns; the in-page modal did none
                // of those, so the same sheet behaved differently
                // depending on where it was uploaded from.
                ->url(ImportWizard::getUrl(['type' => 'batches']))
                // Also gated on the wizard's own access check, so the
                // button is never shown to someone it would 403.
                ->visible(fn (): bool => ImportWizard::canAccess()
                    && (auth()->user()?->can('create', Batch::class) ?? false)),

            Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('view_any_batch') ?? false)
                ->action(fn () => $this->exportToCsv()),

            // Synthesised xlsx (no legacy sample for Batch alone — the
            // concept was buried inside Batch_List_Sample). Column names
            // match BatchImporter 1:1 so download → fill → re-upload needs
            // no remapping.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('batch'))
                ->visible(fn () => auth()->user()?->can('create', Batch::class) ?? false),

            Actions\CreateAction::make(),
        ];
    }

    /**
     * Sanitize a single CSV cell to neutralize spreadsheet formula injection.
     *
     * fputcsv only escapes CSV grammar (commas, quotes, newlines) — it does NOT
     * defend against Excel/LibreOffice/Sheets interpreting a leading "=", "+",
     * "-", "@", TAB or CR as a formula trigger (CWE-1236 / OWASP "CSV Injection").
     *
     * OWASP-recommended mitigation: prefix dangerous leading characters with a
     * single quote (') — both Excel and LibreOffice treat that as "show literally,
     * do not evaluate".
     */
    private function sanitizeCsvCell(mixed $value): string
    {
        $string = (string) ($value ?? '');
        if ($string === '') {
            return '';
        }
        if (preg_match('/^[=+\-@\t\r]/', $string)) {
            return "'" . $string;
        }

        return $string;
    }
}
