<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\AuditSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * RFQ §3.1.8 — Audit settings page.
 *
 * Lets super-administrators toggle audit writing on/off and configure
 * the audit retention threshold. Changes are persisted via
 * spatie/laravel-settings (group: audit) and applied at runtime via
 * AppServiceProvider::boot() which mirrors the setting into
 * config('audit.enabled').
 *
 * Gated to super_admin ONLY; admins and viewers receive 403 on mount.
 *
 * @property-read Schema $form
 */
class AuditSettingsPage extends Page
{
    /**
     * Form state, bound to the Filament schema via statePath('data').
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    protected string $view = 'filament.pages.settings.audit-settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 70;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Audit settings';

    protected static ?string $title = 'Audit settings';

    protected static ?string $slug = 'settings/audit';

    // ─── access ─────────────────────────────────────────────────────────────

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('super_admin');
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

        $settings = app(AuditSettings::class);

        $this->form->fill([
            'enabled' => $settings->enabled,
            'threshold' => $settings->threshold,
        ]);
    }

    // ─── schema ─────────────────────────────────────────────────────────────

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Section::make('Audit configuration')
                    ->description('Control whether model changes are recorded in the audit log.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Enable audit trail')
                            ->helperText('When off, no new audit records are written. Existing records are preserved.'),

                        TextInput::make('threshold')
                            ->label('Retention threshold (days)')
                            ->integer()
                            ->minValue(0)
                            ->required()
                            ->helperText('Number of days to retain audit records. Set to 0 to keep indefinitely.'),
                    ]),
            ]);
    }

    // ─── persistence ────────────────────────────────────────────────────────

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $settings = app(AuditSettings::class);
        $settings->enabled = (bool) $state['enabled'];
        $settings->threshold = (int) $state['threshold'];
        $settings->save();

        Notification::make()
            ->title('Audit settings saved')
            ->body('Changes will take effect on the next request.')
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
