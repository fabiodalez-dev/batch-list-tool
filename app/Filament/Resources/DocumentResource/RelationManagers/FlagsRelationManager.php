<?php

namespace App\Filament\Resources\DocumentResource\RelationManagers;

use App\Models\DocumentFlag;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders the Document's flags timeline on the Document edit/view page
 * (RFQ §3.1.12 — replacement for spreadsheet colour-coding).
 *
 * Default filter: status = open + acknowledged (operators almost always
 * want to see what still needs their attention, not what's archived).
 * Switching the filter to "all" reveals the full history including resolved
 * and dismissed rows so the audit trail is never hidden, just collapsed.
 *
 * Write actions (create, mark resolved/dismissed/acknowledged) are gated on
 * the Filament-Shield-generated permissions for the DocumentFlag resource;
 * a custom `resolve_document::flag` permission gates the workflow
 * transitions specifically so reviewers can be allowed to close flags
 * without being able to update their content.
 */
class FlagsRelationManager extends RelationManager
{
    protected static string $relationship = 'flags';

    protected static ?string $title = 'Issue flags';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Schemas\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('type')
                    ->options(self::typeOptions())
                    ->required()
                    ->native(false)
                    ->searchable(),

                Forms\Components\Select::make('severity')
                    ->options(self::severityOptions())
                    ->required()
                    ->default('warning')
                    ->native(false),
            ]),

            Forms\Components\TextInput::make('title')
                ->required()
                ->maxLength(200)
                ->helperText('Short one-line summary, visible in the alerts dashboard.'),

            Forms\Components\Textarea::make('description')
                ->rows(3)
                ->maxLength(5000)
                ->helperText('Operator note. Markdown not rendered — kept as plain text on purpose.'),

            // status defaults to "open" at the DB layer; surfaced here so
            // an admin can pre-set a flag as already-resolved (e.g. when
            // back-filling historical data) without a second save.
            Forms\Components\Select::make('status')
                ->options(self::statusOptions())
                ->default('open')
                ->required()
                ->native(false)
                ->visible(fn (string $operation): bool => $operation !== 'create'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('flagged_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        'info' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => self::typeLabel($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->limit(50)
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'open' => 'warning',
                        'acknowledged' => 'info',
                        'resolved' => 'success',
                        'dismissed' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('flaggedBy.name')
                    ->label('Flagged by')
                    ->default('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('flagged_at')
                    ->label('Flagged')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::statusOptions() + ['all' => 'All (incl. closed)'])
                    ->default('open')
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? 'open';

                        return match ($value) {
                            'open' => $query->open(),
                            'all', null, '' => $query,
                            default => $query->where('status', $value),
                        };
                    }),

                SelectFilter::make('severity')
                    ->options(self::severityOptions())
                    ->multiple(),

                SelectFilter::make('type')
                    ->options(self::typeOptions())
                    ->multiple(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => static::userCanCreate())
                    ->mutateFormDataUsing(function (array $data): array {
                        $owner = $this->getOwnerRecord();
                        // The model mutator on document_id mirrors
                        // repository_id; we still pass it explicitly here
                        // so factory-style consumers don't surprise us.
                        $data['document_id'] = $owner->getKey();
                        $data['repository_id'] = $owner->repository_id;
                        $data['flagged_by_user_id'] = auth()->id();
                        $data['flagged_at'] = now();

                        return $data;
                    }),
            ])
            ->actions([
                ViewAction::make(),

                EditAction::make()
                    ->visible(fn (DocumentFlag $record): bool => $record->isOpen() && static::userCanUpdate()),

                Action::make('acknowledge')
                    ->label('Acknowledge')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->visible(fn (DocumentFlag $record): bool => $record->status === 'open' && static::userCanResolve())
                    ->requiresConfirmation()
                    ->action(fn (DocumentFlag $record) => $record->markAcknowledged(auth()->user())),

                Action::make('resolve')
                    ->label('Mark resolved')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DocumentFlag $record): bool => $record->isOpen() && static::userCanResolve())
                    ->form([
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Resolution notes')
                            ->rows(3)
                            ->required()
                            ->helperText('Required — explains what was done.'),
                    ])
                    ->action(fn (DocumentFlag $record, array $data) => $record->markResolved(
                        auth()->user(),
                        $data['resolution_notes'] ?? null,
                    )),

                Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (DocumentFlag $record): bool => $record->isOpen() && static::userCanResolve())
                    ->form([
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Why is this not actionable?')
                            ->rows(3)
                            ->required(),
                    ])
                    ->action(fn (DocumentFlag $record, array $data) => $record->markDismissed(
                        auth()->user(),
                        $data['resolution_notes'] ?? null,
                    )),
            ])
            ->bulkActions([]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        // Admins see everything; otherwise check Shield-generated perm.
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return method_exists($user, 'can')
            ? (bool) $user->can('view_any_document::flag')
            : false;
    }

    /* ---------------------------------------------------------------------
     |  Vocabulary helpers — used by the form, table filters, AND the
     |  standalone DocumentFlagResource so the option lists never drift.
     |---------------------------------------------------------------------*/

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        $out = [];
        foreach (DocumentFlag::TYPES as $t) {
            $out[$t] = self::typeLabel($t);
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function severityOptions(): array
    {
        return [
            'info' => 'Info',
            'warning' => 'Warning',
            'critical' => 'Critical',
        ];
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            'open' => 'Open',
            'acknowledged' => 'Acknowledged',
            'resolved' => 'Resolved',
            'dismissed' => 'Dismissed',
        ];
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'needs_review' => 'Needs review',
            'missing_data' => 'Missing data',
            'duplicate_suspect' => 'Duplicate suspect',
            'damaged' => 'Damaged',
            'restoration_needed' => 'Restoration needed',
            'wrongly_catalogued' => 'Wrongly catalogued',
            'authority_mismatch' => 'Authority mismatch',
            'barcode_issue' => 'Barcode issue',
            'disinfestation_overdue' => 'Disinfestation overdue',
            'other' => 'Other',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    /* ---------------------------------------------------------------------
     |  Permission helpers
     |---------------------------------------------------------------------*/

    protected static function userCanCreate(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return method_exists($user, 'can') && $user->can('create_document::flag');
    }

    protected static function userCanUpdate(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return method_exists($user, 'can') && $user->can('update_document::flag');
    }

    protected static function userCanResolve(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        // Custom (non-Shield-default) permission. Falls back to `update`
        // for installs where the permission hasn't been seeded yet, so the
        // workflow doesn't break on a fresh deploy.
        if (method_exists($user, 'can')) {
            return $user->can('resolve_document::flag') || $user->can('update_document::flag');
        }

        return false;
    }
}
