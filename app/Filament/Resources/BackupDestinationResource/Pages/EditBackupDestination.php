<?php

namespace App\Filament\Resources\BackupDestinationResource\Pages;

use App\Filament\Resources\BackupDestinationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBackupDestination extends EditRecord
{
    protected static string $resource = BackupDestinationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Strip secret credentials from the decrypted config before the form is
     * hydrated, so the stored secret never reaches the browser. The write-only
     * secret fields (`->dehydrated(fn ($state) => filled($state))`) then keep
     * the existing value intact unless the user types a new one.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (isset($data['config']) && is_array($data['config'])) {
            foreach (['password', 'passphrase', 'secret'] as $secret) {
                unset($data['config'][$secret]);
            }
        }

        return $data;
    }

    /**
     * Merge any unchanged secrets back into config on save. Because the secret
     * fields are write-only and stripped before fill, an empty submitted secret
     * means "keep the existing one" — so we re-read the stored (decrypted) value
     * and preserve it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var array<string, mixed> $existing */
        $existing = $this->getRecord()->config ?? [];

        $config = is_array($data['config'] ?? null) ? $data['config'] : [];

        foreach (['password', 'passphrase', 'secret'] as $secret) {
            if (! array_key_exists($secret, $config) && array_key_exists($secret, $existing)) {
                $config[$secret] = $existing[$secret];
            }
        }

        $data['config'] = $config;

        return $data;
    }
}
