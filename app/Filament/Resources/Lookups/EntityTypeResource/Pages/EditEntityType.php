<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\EntityTypeResource\Pages;

use App\Filament\Resources\Lookups\EntityTypeResource;
use App\Models\Lookup\EntityType;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditEntityType extends EditRecord
{
    protected static string $resource = EntityTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                // Refused here so she reads why; the model refuses it too.
                ->before(function (DeleteAction $action, EntityType $record): void {
                    $reason = $record->deleteBlockedBecause();
                    if ($reason !== null) {
                        Notification::make()->danger()->title('Not deleted')->body($reason)->send();
                        $action->halt();
                    }
                }),
        ];
    }
}
