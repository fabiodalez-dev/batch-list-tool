<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\BoxTypeResource\Pages;

use App\Filament\Resources\Lookups\BoxTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBoxType extends EditRecord
{
    protected static string $resource = BoxTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
