<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\ExplainsPage;
use App\Providers\Filament\AdminPanelProvider;
use App\Settings\BrandingSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

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
    use ExplainsPage;

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
                        Placeholder::make('logo_preview')
                            ->label('Current logo')
                            ->content(function (): HtmlString|string {
                                $path = app(BrandingSettings::class)->logo_path;

                                if (! $path || ! Storage::disk('public')->exists($path)) {
                                    return 'No logo set — the brand name is shown as text.';
                                }

                                $url = Storage::disk('public')->url($path);

                                return new HtmlString(
                                    '<img src="' . e($url) . '" alt="Current logo" style="max-height:4rem">'
                                );
                            }),

                        FileUpload::make('logo_path')
                            ->label('Upload new logo')
                            ->disk('public')
                            ->directory('branding')
                            ->image()
                            ->maxSize(2048)
                            ->helperText('Uploaded image is served locally from the public disk. Leave empty to keep the current logo.'),

                        Toggle::make('clear_logo')
                            ->label('Remove current logo')
                            ->helperText('Toggle on and save to remove the logo (brand name will be shown as text).')
                            ->dehydrated(false)
                            ->default(false)
                            ->visible(fn (): bool => (bool) app(BrandingSettings::class)->logo_path),
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

        // clear_logo is dehydrated=false so it is NOT in $state; read from raw data.
        if (! empty($this->data['clear_logo'])) {
            $settings->logo_path = null;
        } elseif (! empty($state['logo_path'])) {
            // Only overwrite logo_path when the upload widget produced a new value.
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
            Action::make('remove_logo')
                ->label('Remove logo')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove logo')
                ->modalDescription('This will clear the current logo. The brand name will be shown as text until a new logo is uploaded.')
                ->visible(fn (): bool => (bool) app(BrandingSettings::class)->logo_path)
                ->action(function (): void {
                    abort_unless(static::canAccess(), 403);

                    $settings = app(BrandingSettings::class);
                    $settings->logo_path = null;
                    $settings->save();

                    Notification::make()
                        ->title('Logo removed')
                        ->body('The logo has been cleared. Upload a new one to restore branding.')
                        ->success()
                        ->send();
                }),

            Action::make('save')
                ->label('Save changes')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action(fn () => $this->save()),
        ];
    }
}
