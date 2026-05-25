<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportToCsv()),

            Actions\CreateAction::make(),
        ];
    }

    /**
     * Stream the currently filtered Document list as CSV.
     * - Honours every active filter / search term (uses the same query the table
     *   is currently displaying via getFilteredTableQuery()).
     * - Uses fputcsv + streamDownload to stay memory-safe for 50k+ rows.
     * - Loads only the columns we render; eager-loads relations to avoid N+1.
     */
    public function exportToCsv(): StreamedResponse
    {
        $columns = [
            'identifier'          => 'Identifier',
            'document_type'       => 'Type',
            'creator'             => 'Creator(s)',
            'series'              => 'Series',
            'batch'               => 'Batch',
            'current_box'         => 'Current box',
            'disinfestation_date' => 'Disinfestation date',
            'notes'               => 'Notes',
        ];

        $user = auth()->user();
        $repoCode = optional($user?->defaultRepository ?? null)->code ?? 'all';
        $filename = sprintf(
            'documents_%s_%s.csv',
            Str::slug($repoCode, '_'),
            now()->format('Ymd_His'),
        );

        // Snapshot the filtered query NOW (before streaming starts) so it
        // reflects the user's current filters / search / sort.
        $query = $this->getFilteredTableQuery()
            ->with([
                'series:id,code',
                'batch:id,batch_number',
                'currentBox:id,box_number',
                'authorities:id,surname',
            ]);

        return response()->streamDownload(function () use ($query, $columns): void {
            $out = fopen('php://output', 'wb');
            // UTF-8 BOM — Excel on Windows needs it for non-ASCII (Maltese accents).
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($columns));

            $query->orderBy('id')->chunk(500, function ($documents) use ($out): void {
                /** @var \Illuminate\Support\Collection<int, Document> $documents */
                foreach ($documents as $doc) {
                    fputcsv($out, [
                        (string) ($doc->identifier ?? ''),
                        (string) ($doc->document_type ?? ''),
                        $doc->authorities->pluck('surname')->filter()->implode('; '),
                        (string) ($doc->series?->code ?? ''),
                        (string) ($doc->batch?->batch_number ?? ''),
                        (string) ($doc->currentBox?->box_number ?? ''),
                        $doc->disinfestation_date ? $doc->disinfestation_date->format('Y-m-d') : '',
                        (string) ($doc->notes ?? ''),
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'              => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
