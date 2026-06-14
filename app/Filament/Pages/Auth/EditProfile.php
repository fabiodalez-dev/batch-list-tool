<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Filament\Concerns\ExplainsPage;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Custom profile page — extends Filament's stock EditProfile to append a
 * "Default repository" select whose options are constrained to repositories
 * the authenticated user actually belongs to.
 *
 * The server-side guard mirrors the identical logic in PreferencesPage.
 */
class EditProfile extends \Filament\Auth\Pages\EditProfile
{
    use ExplainsPage;

    /**
     * Append the default_repository_id select after the stock fields.
     */
    public function form(Schema $schema): Schema
    {
        /** @var User $user */
        $user = $this->getUser();

        $options = $user
            ->repositories()
            ->pluck('repositories.name', 'repositories.id')
            ->all();

        // Call parent to get the standard components (name / email / password
        // / password confirmation / current password), then append our field.
        $parent = parent::form($schema);

        return $parent->components([
            ...$parent->getComponents(),
            Select::make('default_repository_id')
                ->label('Default repository')
                ->options($options)
                ->nullable()
                ->searchable()
                ->helperText('The repository that will be pre-selected when you open the panel.'),
        ]);
    }

    /**
     * Before persisting, guard that the chosen repository belongs to this user.
     * (PreferencesPage no longer holds the repository selector; the check lives here.)
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = parent::mutateFormDataBeforeSave($data);

        $chosenId = $data['default_repository_id'] ?? null;

        if ($chosenId !== null) {
            /** @var User $user */
            $user = $this->getUser();

            $allowed = $user->repositories()->pluck('repositories.id')->all();

            if (! in_array((int) $chosenId, array_map(intval(...), $allowed), strict: true)) {
                throw ValidationException::withMessages([
                    'data.default_repository_id' => __('You may only select a repository you are assigned to.'),
                ]);
            }
        }

        return $data;
    }
}
