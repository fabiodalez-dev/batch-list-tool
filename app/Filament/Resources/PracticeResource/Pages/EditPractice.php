<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeResource\Pages;

use App\Filament\Resources\PracticeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPractice extends EditRecord
{
    protected static string $resource = PracticeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
