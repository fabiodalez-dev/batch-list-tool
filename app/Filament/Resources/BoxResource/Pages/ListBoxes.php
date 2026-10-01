<?php

declare(strict_types=1);

namespace App\Filament\Resources\BoxResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Concerns\ExportsLikeTheTemplate;
use App\Filament\Imports\BoxImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\BoxResource;
use App\Models\Box;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBoxes extends ListRecords
{
    use ExplainsPage;
    use ExportsLikeTheTemplate;

    protected static string $resource = BoxResource::class;

    protected static function exportEntity(): string
    {
        return 'box';
    }

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
            Actions\Action::make('import')
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                // One import path. The wizard orders parents ahead of
                // children, offers the overwrite choice and warns about
                // missing structural columns; the in-page modal did none
                // of those, so the same sheet behaved differently
                // depending on where it was uploaded from.
                ->url(ImportWizard::getUrl(['type' => 'boxes']))
                // Also gated on the wizard's own access check, so the
                // button is never shown to someone it would 403.
                ->visible(fn (): bool => ImportWizard::canAccess()
                    && (auth()->user()?->can('create', Box::class) ?? false)),

            Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('view_any_box') ?? false)
                ->action(fn () => $this->exportToCsv()),

            // Synthesised xlsx — Box has no dedicated legacy sample.
            // Column names match BoxImporter so download → fill → re-upload
            // needs no remapping.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('box'))
                ->visible(fn () => auth()->user()?->can('create', Box::class) ?? false),

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
