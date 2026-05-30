<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\BarcodeStatusResource\Pages;

use App\Filament\Resources\Lookups\BarcodeStatusResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBarcodeStatus extends EditRecord
{
    protected static string $resource = BarcodeStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
