<?php

namespace App\Filament\Resources\BackupDestinationResource\Pages;

use App\Filament\Resources\BackupDestinationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBackupDestination extends CreateRecord
{
    protected static string $resource = BackupDestinationResource::class;

    /**
     * Auto-generate disk_key from the name when the operator leaves it blank,
     * so the technical identifier never has to be invented by hand.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['disk_key'] ?? null)) {
            $data['disk_key'] = BackupDestinationResource::uniqueDiskKey((string) ($data['name'] ?? ''));
        }

        return $data;
    }
}
