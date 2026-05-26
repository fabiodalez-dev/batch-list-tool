<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Action #1 — Move document(s) to a target Box.
 *
 * Validates that the target Box is assignable (not soft-deleted, not
 * PERM_OUT) and writes a {@see BoxMovement} row for each document so the
 * physical chain of custody is preserved (RFQ §3.1.5).
 *
 * Both the single-record and bulk variants share the same form (target box
 * + optional reason) and the same writer body — only the way `records` is
 * sourced differs.
 */
final class MoveToBoxAction
{
    public static function make(string $name = 'moveToBox'): Action
    {
        return Action::make($name)
            ->label('Move to box')
            ->icon('heroicon-o-archive-box-arrow-down')
            ->color('primary')
            ->modalHeading('Move document to a different box')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkMoveToBox'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Move to box')
            ->icon('heroicon-o-archive-box-arrow-down')
            ->color('primary')
            ->modalHeading('Move selected documents to a different box')
            ->form(self::form())
            ->action(function (EloquentCollection $records, array $data): void {
                self::perform($records, $data);
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    /**
     * @return array<int, Component>
     */
    private static function form(): array
    {
        return [
            SearchableSelects::box('to_box_id', 'currentBox')
                ->label('Target box')
                ->required(),
            Textarea::make('reason')
                ->label('Reason (optional)')
                ->maxLength(255)
                ->rows(2)
                ->placeholder('Why is this document being moved?'),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $targetBoxId = (int) ($data['to_box_id'] ?? 0);
        $reason = $data['reason'] ?? null;

        /** @var Box|null $targetBox */
        $targetBox = Box::query()->find($targetBoxId);
        if ($targetBox === null) {
            Notification::make()
                ->title('Cannot move — target box not found')
                ->body('The selected box was deleted or is no longer accessible.')
                ->danger()
                ->send();

            return;
        }

        if ($targetBox->trashed() || $targetBox->barcode_status === 'PERM_OUT') {
            Notification::make()
                ->title('Cannot move — target box is not assignable')
                ->body('The selected box is destroyed or permanently transferred out.')
                ->danger()
                ->send();

            return;
        }

        $ok = 0;
        $errors = [];

        DB::transaction(function () use ($records, $targetBox, $reason, &$ok, &$errors): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                try {
                    $from = $doc->current_box_id;
                    $doc->current_box_id = $targetBox->getKey();
                    // Aligning batch with the box's batch keeps documents.batch_id
                    // consistent with documents.current_box_id (defence-in-depth
                    // — Filament forms enforce this, but power-actions must too).
                    if ($targetBox->batch_id !== null) {
                        $doc->batch_id = $targetBox->batch_id;
                    }
                    $doc->save();

                    BoxMovement::create([
                        'document_id' => $doc->getKey(),
                        'from_box_id' => $from,
                        'to_box_id' => $targetBox->getKey(),
                        'movement_date' => now(),
                        'reason' => $reason,
                        'user_id' => auth()->id(),
                    ]);

                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        self::notify($ok, $errors, "moved to Box {$targetBox->box_number}");
    }

    /**
     * @param array<int, string> $errors
     */
    private static function notify(int $ok, array $errors, string $verb): void
    {
        if ($errors === [] && $ok > 0) {
            Notification::make()
                ->title("{$ok} document(s) {$verb}")
                ->success()
                ->send();

            return;
        }

        if ($ok > 0 && $errors !== []) {
            Notification::make()
                ->title("Partial: {$ok} {$verb}, " . count($errors) . ' failed')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Action failed')
            ->body($errors === [] ? 'No documents were processed.' : implode("\n", array_slice($errors, 0, 5)))
            ->danger()
            ->send();
    }
}
