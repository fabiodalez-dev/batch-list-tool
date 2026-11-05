<?php

declare(strict_types=1);

namespace App\Filament\Actions\Boxes;

use App\Models\Box;
use Filament\Actions\BulkAction;
use Illuminate\Support\Collection;

/**
 * Client 2026-11-05: "Mark PERM_OUT: should be in Boxes Bulk action. We can also
 * add Mark OUT and Mark IN". The box owns the barcode status, so these replace
 * the document action. RAS boxes only: only they have a barcode status.
 *
 * Through the model, so its guards answer per box (PERM OUT to OUT refused,
 * PERM OUT to IN needs a new barcode), history and mirror happen, and Mark IN
 * takes the location RAS through the saving hook.
 */
final class MarkBoxStatusAction
{
    public static function bulk(string $status): BulkAction
    {
        $label = str_replace('_', ' ', $status);
        $description = match ($status) {
            'IN' => 'RAS boxes go IN and take the location RAS. A box at PERM OUT needs a new barcode, so it is not changed here.',
            'OUT' => 'RAS boxes go OUT. A box at PERM OUT cannot change to OUT.',
            default => 'RAS boxes go PERM OUT: a permanent transfer, and going back needs a new barcode.',
        };

        return BulkAction::make('mark' . str_replace(' ', '', ucwords(strtolower($label))))
            ->label('Mark ' . $label)
            ->icon(match ($status) {
                'IN' => 'heroicon-o-arrow-down-on-square',
                'OUT' => 'heroicon-o-arrow-up-on-square',
                default => 'heroicon-o-archive-box-x-mark',
            })
            ->color($status === 'PERM_OUT' ? 'danger' : 'gray')
            ->requiresConfirmation()
            ->modalHeading("Mark the selected boxes {$label}")
            ->modalDescription($description)
            ->authorize(fn (): bool => (bool) auth()->user()?->can('update_box'))
            ->fetchSelectedRecords(false)
            ->action(function (Collection $records) use ($status, $label): void {
                $result = BoxBulkSupport::run($records, static function (Box $box) use ($status): void {
                    self::mark($box, $status);
                });

                BoxBulkSupport::notify($result, "marked {$label}", "Nothing was marked {$label}");
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function mark(Box $box, string $status): void
    {
        if ($box->box_type !== 'RAS') {
            throw new \DomainException('Not a RAS box: only RAS boxes have a barcode status.');
        }
        if ($box->isDestroyed()) {
            throw new \DomainException('The box is destroyed.');
        }
        if ($box->barcode_status === $status) {
            throw new \DomainException('Already ' . str_replace('_', ' ', $status) . '.');
        }

        $box->barcode_status = $status;
        $box->save();
    }
}
