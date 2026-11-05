<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\CustodyStatusTypeResource\Pages;

use App\Filament\Resources\Lookups\CustodyStatusTypeResource;
use App\Models\Lookup\CustodyStatusType;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCustodyStatusType extends EditRecord
{
    protected static string $resource = CustodyStatusTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->hidden(fn (CustodyStatusType $record): bool => $record->isProtected())
                // Refused here so she reads why; the model refuses it too.
                ->before(function (DeleteAction $action, CustodyStatusType $record): void {
                    $reason = $record->deleteBlockedBecause();
                    if ($reason !== null) {
                        Notification::make()->danger()->title('Not deleted')->body($reason)->send();
                        $action->halt();
                    }
                }),
        ];
    }
}
