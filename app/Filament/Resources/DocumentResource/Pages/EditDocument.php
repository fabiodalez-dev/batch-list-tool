<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Actions\Documents\DocumentActionGroup;
use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            // The 13 single-record power-actions live in a dropdown group
            // so the header stays scannable. ActionGroup is the Filament 5
            // way to nest header actions under a single trigger.
            ActionGroup::make(DocumentActionGroup::singleHeaderActions())
                ->label('Actions')
                ->icon('heroicon-o-bolt')
                ->color('primary')
                ->button(),
            Actions\DeleteAction::make(),
        ];
    }
}
