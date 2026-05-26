<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Action #6 — Mark document(s) as PERM_OUT (permanently transferred out).
 *
 * RFQ App.1 #5 — a document cannot be marked PERM_OUT unless its
 * `disinfestation_date` is already set. Documents that fail this check
 * are skipped and reported in the partial-success notification rather than
 * aborting the whole bulk operation.
 *
 * The Document model uses an integer barcode_status only on the box-level
 * (Box::BARCODE_STATUSES), but legacy POC documents carry a barcode in
 * `documents.barcode_in`. Per the implementation brief, we update the
 * document-level status using the same vocabulary as Box; if a
 * `barcode_status` column exists on documents it is written, otherwise
 * the change is logged via the audit row only — defensive against schema
 * drift between the live MySQL and the SQLite test driver.
 */
final class MarkPermOutAction
{
    public static function make(string $name = 'markPermOut'): Action
    {
        return Action::make($name)
            ->label('Mark PERM_OUT')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->modalHeading('Permanently transfer out')
            ->modalDescription('This is permanent. Make sure the disinfestation date is recorded first.')
            ->requiresConfirmation()
            ->action(function (Document $record): void {
                self::perform(ActionSupport::asCollection($record));
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkMarkPermOut'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Mark PERM_OUT')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->modalHeading('Permanently transfer out selected documents')
            ->modalDescription('This is permanent. Each document must already have a disinfestation date.')
            ->requiresConfirmation()
            ->action(function (EloquentCollection $records): void {
                self::perform($records);
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    /**
     * @param EloquentCollection<int, Document> $records
     */
    private static function perform(EloquentCollection $records): void
    {
        $ok = 0;
        $errors = [];

        $hasStatusColumn = Schema::hasColumn('documents', 'barcode_status');

        DB::transaction(function () use ($records, &$ok, &$errors, $hasStatusColumn): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                if ($doc->disinfestation_date === null) {
                    $errors[] = "#{$doc->identifier}: cannot mark PERM_OUT without disinfestation date — mark disinfested first";
                    continue;
                }

                try {
                    if ($hasStatusColumn) {
                        // Use setAttribute() so we don't depend on a Document
                        // model property declaration — `barcode_status` is a
                        // schema-conditional column not in the model $fillable
                        // list.
                        $doc->setAttribute('barcode_status', 'PERM_OUT');
                    }

                    // Always log the intent via an explicit audit row so the
                    // PERM_OUT decision is queryable even on databases without
                    // a documents.barcode_status column.
                    ActionSupport::logPivotChange(
                        document: $doc,
                        event: 'permout_marked',
                        newValues: ['barcode_status' => 'PERM_OUT'],
                        oldValues: ['barcode_status' => $doc->getOriginal('barcode_status')],
                        tags: 'permout,document',
                    );

                    if ($doc->isDirty()) {
                        $doc->save();
                    }

                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        if ($errors === [] && $ok > 0) {
            Notification::make()
                ->title("{$ok} document(s) marked PERM_OUT")
                ->success()->send();

            return;
        }

        if ($ok > 0) {
            Notification::make()
                ->title("Partial: {$ok} marked, " . count($errors) . ' blocked')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()->send();

            return;
        }

        Notification::make()
            ->title('Cannot mark PERM_OUT without disinfestation date — mark disinfested first')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents updated.')
            ->danger()->send();
    }
}
