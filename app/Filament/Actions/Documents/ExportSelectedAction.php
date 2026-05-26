<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Series;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

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
            ->action(function (EloquentCollection $records) {
                return self::perform($records);
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->can('view_any_document') ?? false);
    }

    /**
     * @param EloquentCollection<int, Document> $records
     */
    private static function perform(EloquentCollection $records): mixed
    {
        if ($records->isEmpty()) {
            Notification::make()->title('No rows selected')->warning()->send();

            return null;
        }

        // Eager-load relations for the columns we render.
        $records->loadMissing(['series:id,code', 'batch:id,batch_number', 'currentBox:id,box_number', 'authorities:id,surname']);

        $filename = sprintf(
            'documents_selected_%s.csv',
            Str::slug(now()->format('Ymd_His')),
        );

        return response()->streamDownload(function () use ($records): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Identifier', 'Type', 'Creator(s)', 'Series', 'Batch', 'Current box', 'Disinfestation date', 'Notes']);

            foreach ($records as $doc) {
                /** @var Document $doc */
                /** @var Series|null $series */
                $series = $doc->series;
                /** @var Batch|null $batch */
                $batch = $doc->batch;
                /** @var Box|null $box */
                $box = $doc->currentBox;
                fputcsv($out, [
                    self::sanitize($doc->identifier),
                    self::sanitize($doc->document_type),
                    self::sanitize($doc->authorities->pluck('surname')->filter()->implode('; ')),
                    self::sanitize($series?->code),
                    $batch === null ? '' : (string) $batch->batch_number,
                    self::sanitize($box?->box_number),
                    $doc->disinfestation_date ? $doc->disinfestation_date->format('Y-m-d') : '',
                    self::sanitize($doc->notes),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function sanitize(mixed $value): string
    {
        $s = (string) ($value ?? '');
        if ($s === '') {
            return '';
        }
        if (preg_match('/^[=+\-@\t\r]/', $s)) {
            return "'" . $s;
        }

        return $s;
    }
}
