<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolumeResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Concerns\ExportsLikeTheTemplate;
use App\Filament\Imports\VolumeImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\VolumeResource;
use App\Models\Volume;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVolumes extends ListRecords
{
    use ExplainsPage;
    use ExportsLikeTheTemplate;

    protected static string $resource = VolumeResource::class;

    protected static function exportEntity(): string
    {
        return 'volume';
    }

    protected function getHeaderActions(): array
    {
        return [
            // Import Excel / CSV — links to the wizard, the single import path.
            // RFQ rules enforced by VolumeImporter:
            //   - document_identifier must resolve to an existing document in
            //     the active repository (multi-tenant guard).
            //   - Custom-field columns are applied with merge semantics so a
            //     partial CSV does not wipe unmentioned fields.
            Actions\Action::make('import')
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                // One import path. The wizard orders parents ahead of
                // children, offers the overwrite choice and warns about
                // missing structural columns; the in-page modal did none
                // of those, so the same sheet behaved differently
                // depending on where it was uploaded from.
                ->url(ImportWizard::getUrl(['type' => 'volumes']))
                // Also gated on the wizard's own access check, so the
                // button is never shown to someone it would 403.
                ->visible(fn (): bool => ImportWizard::canAccess()
                    && (auth()->user()?->can('create_volume') ?? false)),

            Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('view_any_volume') ?? false)
                ->action(fn () => $this->exportToCsv()),

            // Download a blank template whose column headers match the
            // VolumeImporter column keys exactly — so download → fill → re-upload
            // needs no column remapping. Volume headers are defined by
            // ColumnLabels::DEFAULTS['volume'].
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('volume'))
                ->visible(fn () => auth()->user()?->can('create_volume') ?? false),

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
