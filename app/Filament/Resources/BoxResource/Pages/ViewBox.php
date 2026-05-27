<?php

namespace App\Filament\Resources\BoxResource\Pages;

use App\Filament\Actions\Boxes\DestroyBoxAction;
use App\Filament\Resources\BoxResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewBox extends ViewRecord
{
    protected static string $resource = BoxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            // RFQ App.2 §vii — "Mark as destroyed". The action hides
            // itself on already-destroyed boxes (see visible() callback),
            // so we always register it here.
            DestroyBoxAction::make(),
        ];
    }
}
