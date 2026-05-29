<?php

declare(strict_types=1);

namespace App\Filament\Pages\Account;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * My account › Preferences page.
 *
 * Self-service page where the authenticated user sets per-user display
 * preferences: preferred table page size, UI locale, and display timezone.
 *
 * NOTE: The default-repository selector was moved to the Profile page
 * (EditProfile) to avoid duplication. It is no longer rendered here.
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

    protected static ?int $navigationSort = 30;

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

        $user = auth()->user();

        $this->form->fill([
            'preferred_page_size' => $user?->preferred_page_size ?? 25,
            'locale' => $user?->locale,
            'timezone' => $user?->timezone,
        ]);
    }

    // ─── schema ─────────────────────────────────────────────────────────────

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Select::make('preferred_page_size')
                    ->label('Default table page size')
                    ->options([
                        10 => '10',
                        25 => '25',
                        50 => '50',
                        100 => '100',
                    ])
                    ->required()
                    ->helperText('Number of rows shown per page in every table.'),

                Select::make('locale')
                    ->label('Language')
                    ->options([
                        'en' => 'English',
                        'it' => 'Italiano',
                    ])
                    ->nullable()
                    ->placeholder('System default')
                    ->helperText('UI language. Leave blank to use the system default.'),

                Select::make('timezone')
                    ->label('Display timezone')
                    ->options(
                        collect(\DateTimeZone::listIdentifiers())
                            ->mapWithKeys(fn (string $tz) => [$tz => $tz])
                            ->all()
                    )
                    ->nullable()
                    ->searchable()
                    ->placeholder('System default (UTC)')
                    ->helperText('Timezone used when displaying dates and times.'),
            ]);
    }

    // ─── persistence ────────────────────────────────────────────────────────

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $user = auth()->user();

        $user->update([
            'preferred_page_size' => $state['preferred_page_size'] ?? 25,
            'locale' => $state['locale'] ?? null,
            'timezone' => $state['timezone'] ?? null,
        ]);

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
