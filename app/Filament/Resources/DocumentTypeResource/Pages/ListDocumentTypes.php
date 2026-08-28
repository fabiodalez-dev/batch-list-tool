<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTypeResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\DocumentTypeResource;
use App\Models\DocumentType;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDocumentTypes extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = DocumentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Blank .xlsx whose row-1 headers match the Document Types import
            // contract (#17). Bulk creation itself is done through the Import
            // Wizard — the button below links straight to it.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('documentType'))
                ->visible(fn () => auth()->user()?->can('create', DocumentType::class) ?? false),

            // Subtle pointer to where a filled-in template is uploaded. Opens
            // the Import Wizard in the same tab.
            Actions\Action::make('open_import_wizard')
                ->label('Bulk import via wizard')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->url(fn (): string => ImportWizard::getUrl())
                ->visible(fn () => auth()->user()?->can('create', DocumentType::class) ?? false),

            Actions\CreateAction::make(),
        ];
    }
}
