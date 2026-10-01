<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use App\Support\Export\EntityExport;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Action #15 — Export ONLY the rows the operator selected, as a CSV.
 *
 * Distinct from {@see ListDocuments::exportToCsv()}
 * which honours the active filters; this one honours the explicit row
 * selection, which is the common "I checked these 12 boxes, I want THOSE in
 * a spreadsheet" use case (RFQ §3.1.1 + §3.1.4).
 */
final class ExportSelectedAction
{
    public static function bulk(string $name = 'bulkExportSelected'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Export selected (CSV)')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(fn (EloquentCollection $records) => self::perform($records))
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->can('view_any_document') ?? false);
    }

    /**
     * The selected rows, in the documents import template's columns — the same
     * file shape as the list's Export CSV, so a selection can be edited and
     * re-imported too (see EntityExport). Field permissions apply there.
     *
     * @param EloquentCollection<int, Document> $records
     */
    private static function perform(EloquentCollection $records): ?StreamedResponse
    {
        if ($records->isEmpty()) {
            Notification::make()->title('No rows selected')->warning()->send();

            return null;
        }

        abort_unless(auth()->user()?->can('view_any_document'), 403, 'Not authorized to export documents.');

        return EntityExport::stream(
            'document',
            Document::query()->whereKey($records->modelKeys()),
            'documents_selected',
        );
    }
}
