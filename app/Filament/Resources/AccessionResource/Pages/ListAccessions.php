<?php

namespace App\Filament\Resources\AccessionResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Concerns\ExportsLikeTheTemplate;
use App\Filament\Resources\AccessionResource;
use App\Models\Document;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAccessions extends ListRecords
{
    use ExplainsPage;
    use ExportsLikeTheTemplate;

    protected static string $resource = AccessionResource::class;

    protected static function exportEntity(): string
    {
        return 'accession';
    }

    /**
     * The accession sheet is one row per document, so the export is the
     * documents of the accessions the table is showing — in the accession
     * template's columns, ready to edit and re-import.
     *
     * @return Builder<Document>
     */
    protected function exportQuery(): Builder
    {
        return Document::query()->whereIn('accession_id', $this->getFilteredTableQuery()->select('accessions.id'));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->exportCsvAction(),

            Actions\CreateAction::make(),
        ];
    }
}
