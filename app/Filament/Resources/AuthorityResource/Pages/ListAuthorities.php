<?php

namespace App\Filament\Resources\AuthorityResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\AuthorityResource;
use App\Models\Authority;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAuthorities extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = AuthorityResource::class;

    /**
     * Header actions for the Authorities list page.
     *
     * The Import button links to {@see ImportWizard} with the record type
     * preselected, rather than carrying its own upload modal. Until
     * 2026-09-17 this page ran a second, parallel import path that skipped the
     * wizard's parent ordering, overwrite choice and structural-column
     * warnings — so the same spreadsheet behaved differently depending on
     * where it was uploaded from.
     *
     * Visibility is gated through the {@see Authority} policy
     * (`create_authority`) AND the wizard's own access check, so the button is
     * never shown to someone the wizard would refuse.
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
                ->url(ImportWizard::getUrl(['type' => 'authorities']))
                // Also gated on the wizard's own access check, so the
                // button is never shown to someone it would 403.
                ->visible(fn (): bool => ImportWizard::canAccess()
                    && (auth()->user()?->can('create', Authority::class) ?? false)),

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
