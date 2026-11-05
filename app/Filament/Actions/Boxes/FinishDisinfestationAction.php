<?php

declare(strict_types=1);

namespace App\Filament\Actions\Boxes;

use App\Models\Box;
use App\Models\Location;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Client 2026-11-05: "Finish Disinfestation": "update the Documents in these
 * boxes with the disinfestation date that is inputted by the user. This would be
 * the latest disinfestation date of the document." Then either back to RAS (RAS
 * boxes IN), or to an archive location (RAS boxes PERM OUT). Other box types
 * only change location: they have no barcode status.
 *
 * A date the document already held goes to its first free legacy slot, so
 * Document::disinfestationTimeline() keeps it. With no free slot the date would
 * be lost, so that box is refused and the documents are named: it needs a person.
 *
 * Her wording is "from Location:Disinfestation", so a box anywhere else is
 * refused. Status changes go through the model, for the history and the mirror.
 */
final class FinishDisinfestationAction
{
    public const string TO_RAS = 'ras';

    public const string TO_ARCHIVE = 'archive';

    private const array HISTORY_SLOTS = ['disinfestation_date_1', 'disinfestation_date_2', 'disinfestation_date_3'];

    public static function bulk(string $name = 'finishDisinfestation'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Finish disinfestation')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->modalHeading('Finish disinfestation of the selected boxes')
            ->modalDescription('Only boxes at the location Disinfestation are finished. The date becomes the latest disinfestation date of their documents.')
            ->form([
                DatePicker::make('disinfestation_date')
                    ->label('Disinfestation date')
                    ->required()
                    ->default(now()->toDateString())
                    ->maxDate(now())
                    ->helperText('No future dates — record the actual fumigation date.'),
                Radio::make('destination')
                    ->label('Destination')
                    ->options([
                        self::TO_RAS => 'Return to RAS',
                        self::TO_ARCHIVE => 'To an archive location (NRA)',
                    ])
                    ->required()
                    ->live(),
                BoxBulkSupport::archiveLocationSelect('location_id')
                    ->label('Archive location')
                    ->visible(fn (Get $get): bool => $get('destination') === self::TO_ARCHIVE)
                    ->required(fn (Get $get): bool => $get('destination') === self::TO_ARCHIVE),
            ])
            ->authorize(fn (): bool => (bool) (auth()->user()?->can('update_box') && auth()->user()->can('update_document')))
            ->fetchSelectedRecords(false)
            ->action(function (Collection $records, array $data): void {
                self::perform($records, $data);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @param Collection<int, mixed> $records
     * @param array<string, mixed> $data
     */
    private static function perform(Collection $records, array $data): void
    {
        $failed = 'Disinfestation not finished';

        // Checked again here, past the form's maxDate, as MarkDisinfestedAction does.
        $date = filled($data['disinfestation_date'] ?? null) ? Carbon::parse($data['disinfestation_date']) : null;
        if ($date === null || $date->isFuture()) {
            BoxBulkSupport::stop($failed . ': the disinfestation date is required and cannot be in the future.');

            return;
        }
        $date = $date->toDateString();

        $toArchive = ($data['destination'] ?? null) === self::TO_ARCHIVE;
        $archive = null;
        $rasTargets = [];

        if ($toArchive) {
            $archive = BoxBulkSupport::archiveLocation($data['location_id'] ?? null);
            if ($archive === null) {
                BoxBulkSupport::stop($failed . ': the archive location must be an active location of type Repository or Room.');

                return;
            }
        } else {
            $rasTargets = BoxBulkSupport::namedLocations($records, Location::NAME_RAS);
            if ($rasTargets === null) {
                BoxBulkSupport::stop($failed, 'There is no active location named "' . Location::NAME_RAS . '" for these boxes. Add it under Locations and try again.');

                return;
            }
        }

        $result = BoxBulkSupport::run($records, static function (Box $box) use ($date, $archive, $rasTargets): void {
            if ($archive !== null) {
                BoxBulkSupport::assertLocationFitsBox($archive, $box);
            }
            self::finish($box, $date, $archive ?? $rasTargets[(int) $box->effectiveRepositoryId()], $archive !== null);
        });

        // Client 2026-10-30: dates read dd-mm-yyyy throughout the system.
        BoxBulkSupport::notify($result, 'finished disinfestation on ' . Carbon::parse($date)->format('d-m-Y'), $failed);
    }

    private static function finish(Box $box, string $date, Location $target, bool $toArchive): void
    {
        $isRas = $box->box_type === 'RAS';

        if ($box->isDestroyed()) {
            throw new \DomainException('The box is destroyed.');
        }
        $current = $box->location_id !== null ? Location::withoutGlobalScopes()->find($box->location_id) : null;
        if ($current?->name !== Location::NAME_DISINFESTATION) {
            throw new \DomainException('The box is not at the location Disinfestation.');
        }
        if (! $toArchive && $isRas && $box->barcode_status === 'PERM_OUT') {
            throw new \DomainException('The box is PERM OUT: going back to IN needs a new barcode, so edit the box instead.');
        }

        self::stampDocuments($box, $date, $toArchive && $isRas ? 'PERM_OUT' : ($isRas ? 'IN' : $box->barcode_status));

        $box->disinfestation_date = Carbon::parse($date);
        $box->location_id = $target->getKey();
        if ($isRas) {
            $box->barcode_status = $toArchive ? 'PERM_OUT' : 'IN';
        }
        $box->save();
    }

    /**
     * One UPDATE per distinct change, which in a box is usually one or two, and
     * the audit rows MarkDisinfestedAction writes.
     */
    private static function stampDocuments(Box $box, string $date, ?string $status): void
    {
        $rows = BoxBulkSupport::documentsIn($box)
            ->toBase()
            ->get(['id', 'identifier', 'catalogue_identifier', 'disinfestation_date', 'is_in_disinfestation', 'barcode_status', ...self::HISTORY_SLOTS]);

        $updates = [];
        $audits = [];
        $full = [];

        foreach ($rows as $row) {
            $update = ['disinfestation_date' => $date, 'is_in_disinfestation' => false];

            $previous = self::dateOf($row->disinfestation_date);
            $slots = array_map(static fn (string $slot): ?string => self::dateOf($row->{$slot}), array_combine(self::HISTORY_SLOTS, self::HISTORY_SLOTS));
            if ($previous !== null && $previous !== $date && ! in_array($previous, $slots, true)) {
                $free = array_search(null, $slots, true);
                if ($free === false) {
                    $full[] = $row->catalogue_identifier ?? $row->identifier;

                    continue;
                }
                $update[$free] = $previous;
            }

            $updates[json_encode($update, JSON_THROW_ON_ERROR)][] = (int) $row->id;
            $audits[] = [
                'id' => (int) $row->id,
                'old' => ['disinfestation_date' => $previous, 'is_in_disinfestation' => (bool) $row->is_in_disinfestation, 'barcode_status' => $row->barcode_status],
                'new' => $update + ['barcode_status' => $status],
            ];
        }

        if ($full !== []) {
            throw new \DomainException('These documents already hold four disinfestation dates, and the earliest would be lost: ' . implode(', ', array_slice($full, 0, 5)) . (count($full) > 5 ? ' and ' . (count($full) - 5) . ' more' : '') . '.');
        }

        foreach ($updates as $update => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                BoxBulkSupport::documentsIn($box)->whereKey($chunk)->update(json_decode($update, true, flags: JSON_THROW_ON_ERROR));
            }
        }

        BoxBulkSupport::auditDocuments($audits, 'document.marked_disinfested', 'disinfestation,document');
    }

    private static function dateOf(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->toDateString() : null;
    }
}
