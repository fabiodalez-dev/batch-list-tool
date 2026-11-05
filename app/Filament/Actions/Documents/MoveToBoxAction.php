<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Location;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Action #1 — Move document(s) to a target Box.
 *
 * Validates that the target Box is assignable (not soft-deleted, not destroyed,
 * not PERM_OUT) and writes a {@see BoxMovement} row for each document so the
 * physical chain of custody is preserved (RFQ §3.1.5).
 *
 * Client 2026-11-05: moving into a box makes the document "In box" whatever it
 * was before (not in box, mounted, conservation box), and changes its Location:
 * the one chosen, else the target box's (its own, or the one its documents
 * share), else none, so the document stops showing where it was before.
 *
 * Per-row atomicity (review C-2): each row runs inside its own
 * `DB::transaction()` via {@see ActionSupport::performBulk()}; a failure
 * partway through a row's writes (document save + BoxMovement insert) rolls
 * back that row only — previously-successful rows are preserved. The
 * operator gets a partial-success notification listing the failures.
 *
 * Multi-tenant safety (review C-3): the target box must be in the same
 * repository as the document, by {@see Box::effectiveRepositoryId()}: its
 * batch's, or its own for a batch-less box. Otherwise the action refuses the
 * row (no cross-tenant writes from privileged users picking a foreign target
 * via the Select).
 *
 * A batched box gives the document its batch. A batch-less box (672 NRA boxes,
 * client 2026-11-05: "can't find it") leaves `documents.batch_id` as it is: the
 * archival RAS batch may legitimately diverge from the current box's, as when
 * the importer repoints `current_box_id` onto a batch-less In-Situ box of the
 * movement timeline (see DocumentImporter).
 *
 * Both the single-record and bulk variants share the same form (target box,
 * optional location, optional reason) and the same writer body — only the way
 * `records` is sourced differs.
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
            // Client 2026-11-05: "NRA 5 … can't find it". Searched the way boxes
            // read ({@see Box::searchOptions()}), not by the digits of a label
            // every box of a batch shares.
            Select::make('to_box_id')
                ->label('Target box')
                ->searchable()
                ->preload()
                ->searchPrompt('Type "NRA 5", "Batch 4 / Box 192", "4/192" or a number')
                ->options(fn (): array => self::boxOptions(''))
                ->getSearchResultsUsing(fn (string $search): array => self::boxOptions($search))
                ->getOptionLabelUsing(function ($value): ?string {
                    $box = Box::query()->with('batch')->find($value);

                    return $box instanceof Box ? SearchableSelects::boxPickLabel($box) : null;
                })
                ->required(),
            SearchableSelects::locationPicker('to_location_id', fn ($q) => $q->where('is_active', true))
                ->label('Location (optional)')
                ->helperText('Leave empty for the location of the target box.'),
            Textarea::make('reason')
                ->label('Reason (optional)')
                ->maxLength(255)
                ->rows(2)
                ->placeholder('Why is this document being moved?'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function boxOptions(string $search): array
    {
        return Box::searchOptions(
            $search,
            SearchableSelects::MAX_RESULTS,
            Box::whereAssignable(...),
            fn (Box $box): string => SearchableSelects::boxPickLabel($box),
        );
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
        $targetBox = Box::query()->with('batch')->find($targetBoxId);
        if ($targetBox === null) {
            Notification::make()
                ->title('Cannot move — target box not found')
                ->body('The selected box was deleted or is no longer accessible.')
                ->danger()
                ->send();

            return;
        }

        if ($targetBox->trashed() || $targetBox->isDestroyed() || $targetBox->barcode_status === 'PERM_OUT') {
            Notification::make()
                ->title('Cannot move — target box is not assignable')
                ->body('The selected box is destroyed or permanently transferred out.')
                ->danger()
                ->send();

            return;
        }

        $chosenLocationId = (int) ($data['to_location_id'] ?? 0);
        $location = $chosenLocationId !== 0
            ? ActionSupport::findActiveLocation($chosenLocationId)
            : self::locationOfBox($targetBox);
        if ($chosenLocationId !== 0 && $location === null) {
            Notification::make()
                ->title('Cannot move — location not found or not active')
                ->danger()
                ->send();

            return;
        }

        $targetBoxRepoId = $targetBox->effectiveRepositoryId();

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($targetBox, $targetBoxRepoId, $location, $reason): void {
                // C-3: per-row tenant gate. Privileged users (super_admin,
                // admin) bypass the Box Select's RepositoryScope, so we MUST
                // re-check here. A box of no repository cannot be vouched for.
                if ($targetBoxRepoId === null || $targetBoxRepoId !== (int) $doc->repository_id) {
                    throw new \DomainException(
                        'target box belongs to a different repository'
                    );
                }
                if ($location !== null) {
                    ActionSupport::assertLocationFits($location, $doc);
                }

                $from = $doc->current_box_id;
                $doc->current_box_id = $targetBox->getKey();
                if ($targetBox->batch_id !== null) {
                    $doc->batch_id = $targetBox->batch_id;
                }
                $doc->custody_status = 'in_box';
                $doc->location_id = $location?->getKey();
                $doc->save();

                BoxMovement::create([
                    'document_id' => $doc->getKey(),
                    'repository_id' => $targetBoxRepoId,
                    'from_box_id' => $from,
                    'to_box_id' => $targetBox->getKey(),
                    'movement_date' => now(),
                    'reason' => $reason,
                    'user_id' => auth()->id(),
                ]);
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: 'moved to ' . $targetBox->shortLabel(),
            failedTitle: 'Move failed',
        );
    }

    /**
     * The target box's own location, else the one its documents share. Read
     * before any document moves in, so that what they bring cannot decide it.
     */
    private static function locationOfBox(Box $box): ?Location
    {
        $location = $box->location_id !== null
            ? Location::withoutGlobalScopes()->find($box->location_id)
            : $box->sharedDocumentLocation();

        return $location instanceof Location && ! $location->trashed() ? $location : null;
    }
}
