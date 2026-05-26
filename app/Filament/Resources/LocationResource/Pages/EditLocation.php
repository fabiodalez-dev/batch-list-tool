<?php

declare(strict_types=1);

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Resources\LocationResource;
use App\Models\Location;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action) {
                    /** @var Location $record */
                    $record = $this->getRecord();
                    if ($record->hasChildren() || $record->isReferenced()) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot delete location')
                            ->body('Location "' . $record->breadcrumb() . '" still has children or is referenced by Boxes/Documents. Re-assign them first.')
                            ->send();
                        $action->cancel();
                    }
                }),
        ];
    }
}
