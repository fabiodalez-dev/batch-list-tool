<?php

declare(strict_types=1);

namespace App\Filament\Actions\Boxes;

use App\Models\Box;
use App\Models\Location;
use Filament\Actions\BulkAction;
use Illuminate\Support\Collection;

/**
 * Client 2026-11-05: "Send to Disinfestation together": the documents in these
 * boxes are toggled as currently in disinfestation, a RAS box goes OUT, and the
 * box's location becomes Disinfestation.
 *
 * Only a RAS box has a barcode status; the others keep theirs. A RAS box at
 * PERM OUT is refused, the model forbids PERM OUT to OUT. Closed by
 * {@see FinishDisinfestationAction}.
 */
final class SendBoxesToDisinfestationAction
{
    public static function bulk(string $name = 'sendToDisinfestation'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Send to disinfestation')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->modalHeading('Send the selected boxes to disinfestation')
            ->modalDescription('Their documents are marked as currently in disinfestation, the boxes move to the location Disinfestation, and RAS boxes go OUT.')
            ->requiresConfirmation()
            ->authorize(fn (): bool => (bool) (auth()->user()?->can('update_box') && auth()->user()->can('update_document')))
            ->fetchSelectedRecords(false)
            ->action(function (Collection $records): void {
                self::perform($records);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @param Collection<int, mixed> $records
     */
    private static function perform(Collection $records): void
    {
        $targets = BoxBulkSupport::namedLocations($records, Location::NAME_DISINFESTATION);
        if ($targets === null) {
            BoxBulkSupport::stop(
                'Nothing was sent to disinfestation',
                'There is no active location named "' . Location::NAME_DISINFESTATION . '" for these boxes. Add it under Locations and try again.',
            );

            return;
        }

        $result = BoxBulkSupport::run($records, static function (Box $box) use ($targets): void {
            self::send($box, $targets[(int) $box->effectiveRepositoryId()]);
        });

        BoxBulkSupport::notify($result, 'sent to disinfestation', 'Nothing was sent to disinfestation');
    }

    private static function send(Box $box, Location $target): void
    {
        $isRas = $box->box_type === 'RAS';

        if ($box->isDestroyed()) {
            throw new \DomainException('The box is destroyed.');
        }
        if ($isRas && $box->barcode_status === 'PERM_OUT') {
            throw new \DomainException('The box is PERM OUT, which cannot change to OUT.');
        }

        $documents = BoxBulkSupport::documentsIn($box)
            ->where('is_in_disinfestation', false)
            ->toBase()
            ->get(['id', 'barcode_status']);

        $nothingToDo = $documents->isEmpty()
            && (int) $box->location_id === (int) $target->getKey()
            && (! $isRas || $box->barcode_status === 'OUT');
        if ($nothingToDo) {
            throw new \DomainException('Already in disinfestation.');
        }

        BoxBulkSupport::documentsIn($box)->whereKey($documents->pluck('id')->all())->update(['is_in_disinfestation' => true]);

        $box->location_id = $target->getKey();
        if ($isRas) {
            $box->barcode_status = 'OUT';
        }
        $box->save();

        BoxBulkSupport::auditDocuments(
            $documents->map(static fn (object $document): array => [
                'id' => (int) $document->id,
                'old' => ['is_in_disinfestation' => false, 'barcode_status' => $document->barcode_status],
                'new' => ['is_in_disinfestation' => true, 'barcode_status' => $box->barcode_status],
            ])->all(),
            'document.sent_to_disinfestation',
            'disinfestation,document',
        );
    }
}
