<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\DigitisationStatusResource\Pages;

use App\Filament\Resources\Lookups\DigitisationStatusResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDigitisationStatus extends EditRecord
{
    protected static string $resource = DigitisationStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
