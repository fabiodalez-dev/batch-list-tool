<?php

declare(strict_types=1);

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\LocationResource;
use App\Models\Location;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLocations extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = LocationResource::class;

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
                ->url(ImportWizard::getUrl(['type' => 'locations']))
                // Also gated on the wizard's own access check, so the
                // button is never shown to someone it would 403.
                ->visible(fn (): bool => ImportWizard::canAccess()
                    && (auth()->user()?->can('create', Location::class) ?? false)),

            // Blank xlsx with the canonical Location import columns
            // (name, type, parent_name, repository_code, code, notes,
            // sort_order, is_active). Gated on the create policy.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('location'))
                ->visible(fn () => auth()->user()?->can('create', Location::class) ?? false),

            Actions\CreateAction::make()
                ->label('New Location'),   // Bug #15
        ];
    }
}
