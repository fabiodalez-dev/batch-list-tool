<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Actions\Documents\DocumentActionGroup;
use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            ActionGroup::make(DocumentActionGroup::singleHeaderActions())
                ->label('Actions')
                ->icon('heroicon-o-bolt')
                ->color('primary')
                ->button(),
        ];
    }
}
