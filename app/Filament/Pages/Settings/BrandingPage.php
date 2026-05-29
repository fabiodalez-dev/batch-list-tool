<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Providers\Filament\AdminPanelProvider;
use App\Settings\BrandingSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * RFQ §3.1.8 — Branding settings page.
 *
 * Lets administrators customise the panel name, logo, logo height and
 * primary colour without touching code. Changes are persisted via
 * spatie/laravel-settings (group: branding) and picked up by
 * {@see AdminPanelProvider} on the next request.
 *
 * Gated to super_admin / admin; viewers are 403-ed on mount.
 *
 * @property-read Schema $form
 */
class BrandingPage extends Page
{
    /**
     * Form state, bound to the Filament schema via statePath('data').
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    protected string $view = 'filament.pages.settings.branding';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 50;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationLabel = 'Branding';

    protected static ?string $title = 'Branding settings';

    protected static ?string $slug = 'settings/branding';

    // ─── access ─────────────────────────────────────────────────────────────

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['super_admin', 'admin']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (auth()->guest()) {
            return true; // CLI / Shield discovery
        }

        return static::canAccess();
    }

    // ─── lifecycle ──────────────────────────────────────────────────────────

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = app(BrandingSettings::class);

        $this->form->fill([
            'brand_name' => $settings->brand_name,
            'logo_path' => $settings->logo_path,
            'logo_height' => $settings->logo_height,
        ]);
    }

    // ─── schema ─────────────────────────────────────────────────────────────

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Section::make('Brand identity')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('brand_name')
                            ->label('Application name')
                            ->required()
                            ->maxLength(191)
                            ->helperText('Displayed in the panel header and browser tab title.'),

                        TextInput::make('logo_height')
                            ->label('Logo height (CSS)')
                            ->required()
                            ->maxLength(32)
                            ->placeholder('2.25rem')
                            ->helperText('A valid CSS length, e.g. 2.25rem or 36px.'),
                    ]),

                Section::make('Logo')
                    ->columns(1)
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label('Logo image')
                            ->disk('public')
                            ->directory('branding')
                            ->image()
                            ->maxSize(2048)
                            ->helperText('Uploaded image is served locally from the public disk. Leave empty to keep the current logo.'),
                    ]),
            ]);
    }

    // ─── persistence ────────────────────────────────────────────────────────

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $settings = app(BrandingSettings::class);
        $settings->brand_name = $state['brand_name'];
        $settings->logo_height = $state['logo_height'];

        // Only overwrite logo_path when the upload widget produced a new value.
        if (! empty($state['logo_path'])) {
            $settings->logo_path = $state['logo_path'];
        }

        $settings->save();

        Notification::make()
            ->title('Branding saved')
            ->body('Changes will be reflected on the next page load.')
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
