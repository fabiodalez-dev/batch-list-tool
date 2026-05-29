<?php

declare(strict_types=1);

namespace App\Filament\Pages\Account;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * RFQ §3.1 — My account › Preferences page.
 *
 * Self-service page where the authenticated user picks their own default
 * repository. The selection is constrained to repositories the user belongs
 * to; any attempt to set a repository outside that set is rejected with a
 * validation error.
 */
class PreferencesPage extends Page
{
    /**
     * Form state, bound to the Filament schema via statePath('data').
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    protected string $view = 'filament.pages.account.preferences';

    protected static string|\UnitEnum|null $navigationGroup = 'My account';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Preferences';

    protected static ?string $title = 'Preferences';

    protected static ?string $slug = 'account/preferences';

    // ─── access ─────────────────────────────────────────────────────────────

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    // ─── lifecycle ──────────────────────────────────────────────────────────

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'default_repository_id' => auth()->user()?->default_repository_id,
        ]);
    }

    // ─── schema ─────────────────────────────────────────────────────────────

    public function form(Schema $schema): Schema
    {
        /** @var User $user */
        $user = auth()->user();

        $options = $user
            ? $user->repositories()->pluck('repositories.name', 'repositories.id')->all()
            : [];

        return $schema
            ->statePath('data')
            ->schema([
                Select::make('default_repository_id')
                    ->label('Default repository')
                    ->options($options)
                    ->nullable()
                    ->searchable()
                    ->helperText('The repository that will be pre-selected when you open the panel.'),
            ]);
    }

    // ─── persistence ────────────────────────────────────────────────────────

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $chosenId = $state['default_repository_id'] ?? null;

        /** @var User $user */
        $user = auth()->user();

        // Server-side guard: the selected repository must belong to this user.
        if ($chosenId !== null) {
            $allowed = $user->repositories()->pluck('repositories.id')->all();

            if (! in_array((int) $chosenId, array_map('intval', $allowed), strict: true)) {
                throw ValidationException::withMessages([
                    'data.default_repository_id' => __('You may only select a repository you are assigned to.'),
                ]);
            }
        }

        $user->update(['default_repository_id' => $chosenId]);

        Notification::make()
            ->title('Preferences saved')
            ->success()
            ->send();
    }

    // ─── actions ────────────────────────────────────────────────────────────

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action(fn () => $this->save()),
        ];
    }
}
