<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentFlag;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Action #11 — Attach an operational flag (RFQ §3.1.12) to document(s).
 *
 * The pivot is kept idempotent: if a document already has an OPEN flag of
 * the same `type`, we don't create a duplicate row — we touch the existing
 * flag's `flagged_at` to surface it as fresh in the inbox.
 */
final class AddFlagAction
{
    public static function make(string $name = 'addFlag'): Action
    {
        return Action::make($name)
            ->label('Add flag')
            ->icon('heroicon-o-flag')
            ->color('warning')
            ->modalHeading('Attach an operational flag to this document')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('create_document_flag') ?? false);
    }

    public static function bulk(string $name = 'bulkAddFlag'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Add flag')
            ->icon('heroicon-o-flag')
            ->color('warning')
            ->modalHeading('Attach an operational flag to selected documents')
            ->form(self::form())
            ->action(function (EloquentCollection $records, array $data): void {
                self::perform($records, $data);
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->can('create_document_flag') ?? false);
    }

    /**
     * @return array<int, Component>
     */
    private static function form(): array
    {
        $typeOptions = [];
        foreach (DocumentFlag::TYPES as $t) {
            $typeOptions[$t] = ucwords(str_replace('_', ' ', $t));
        }

        $sevOptions = [];
        foreach (DocumentFlag::SEVERITIES as $s) {
            $sevOptions[$s] = ucfirst($s);
        }

        return [
            Select::make('type')
                ->label('Flag type')
                ->options($typeOptions)
                ->required()
                ->native(false)
                ->searchable(),
            Select::make('severity')
                ->label('Severity')
                ->options($sevOptions)
                ->default('warning')
                ->required()
                ->native(false),
            TextInput::make('title')
                ->label('Title')
                ->maxLength(200)
                ->placeholder('Short summary (optional)'),
            Textarea::make('description')
                ->label('Description')
                ->maxLength(2000)
                ->rows(3),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $type = (string) ($data['type'] ?? '');
        $severity = (string) ($data['severity'] ?? 'warning');
        $title = $data['title'] ?? null;
        $description = $data['description'] ?? null;

        if (! in_array($type, DocumentFlag::TYPES, true)) {
            Notification::make()
                ->title('Invalid flag type')
                ->danger()->send();

            return;
        }

        if (! in_array($severity, DocumentFlag::SEVERITIES, true)) {
            Notification::make()
                ->title('Invalid severity')
                ->danger()->send();

            return;
        }

        $created = 0;
        $touched = 0;
        $errors = [];

        DB::transaction(function () use ($records, $type, $severity, $title, $description, &$created, &$touched, &$errors): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                try {
                    // Idempotent: if an OPEN flag of this type already exists,
                    // touch flagged_at so it surfaces as fresh; don't create a
                    // duplicate row.
                    $existing = DocumentFlag::query()
                        ->where('document_id', $doc->getKey())
                        ->where('type', $type)
                        ->whereIn('status', DocumentFlag::OPEN_STATUSES)
                        ->first();

                    if ($existing !== null) {
                        $existing->touch('flagged_at');
                        $touched++;
                        continue;
                    }

                    // `title` is NOT NULL at the DB level — fall back to a
                    // human-readable label derived from the flag type when
                    // the operator didn't supply one.
                    $resolvedTitle = is_string($title) && trim($title) !== ''
                        ? trim($title)
                        : ucwords(str_replace('_', ' ', $type));

                    DocumentFlag::create([
                        'document_id' => $doc->getKey(),
                        'type' => $type,
                        'severity' => $severity,
                        'status' => 'open',
                        'title' => $resolvedTitle,
                        'description' => $description,
                    ]);

                    $created++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        if ($errors === [] && ($created + $touched) > 0) {
            $msg = "Flagged {$created} document(s)";
            if ($touched > 0) {
                $msg .= " ({$touched} already had an open flag, refreshed)";
            }
            Notification::make()->title($msg)->success()->send();

            return;
        }

        if (($created + $touched) > 0) {
            Notification::make()
                ->title('Partial: ' . ($created + $touched) . ' flagged, ' . count($errors) . ' failed')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()->send();

            return;
        }

        Notification::make()
            ->title('Flag attach failed')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents updated.')
            ->danger()->send();
    }
}
