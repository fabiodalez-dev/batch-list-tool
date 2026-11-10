<?php

declare(strict_types=1);

namespace App\Filament\Actions\Boxes;

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Lookup\BatchType;
use App\Models\Lookup\CurrentBoxType;
use App\Support\ActiveRepository;
use App\Support\DisplayDate;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;

/**
 * Client 2026-11-10, Operation 1: "move boxes that are currently at NRA back
 * to RAS. These can be both NRA or RAS box types."
 *
 *   - A RAS box keeps its batch and box number. It gets a new barcode (the old
 *     one goes to the barcode history), the location RAS, and status IN.
 *   - Any other type (NRA, MUS, MAV, STVC…) is "treated like an accession": a
 *     new batch is created for the movement, the box joins it as a RAS box
 *     with a new barcode, the location RAS and status IN; a new box number
 *     and a new Current Box Type are optional, per box.
 *
 * One row per box asks for its barcode, so the selection is capped at
 * {@see self::MAX_BOXES}. Each box is changed in its own transaction through
 * {@see BoxBulkSupport::run()}; the model writes the barcode history, the
 * location history and the audit rows. The documents physically in the box
 * follow it to RAS, and for a box that changes batch every document in it
 * moves to the new batch, as with Change batch.
 */
final class ReturnBoxesToRasAction
{
    /** A modal with one barcode row per box stays workable up to this many. */
    public const int MAX_BOXES = 50;

    /** box_location_history.source of the move. */
    public const string LOCATION_SOURCE = 'return_to_ras';

    /** The batch type offered first: the movement is treated like an accession. */
    private const string DEFAULT_BATCH_TYPE = 'NOTARY_ACCESSION';

    public static function make(string $name = 'returnToRas'): Action
    {
        return Action::make($name)
            ->label('Return to RAS')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            // Not offered where there is nothing to do: destroyed, archived, or a
            // RAS box already IN at RAS (the list eager-loads the location).
            ->visible(fn (?Box $record): bool => $record instanceof Box
                && ! $record->isDestroyed()
                && ! $record->trashed()
                && ! ($record->box_type === 'RAS' && $record->barcode_status === 'IN' && $record->location?->getAttribute('name') === Location::NAME_RAS))
            ->modalHeading('Return this box to RAS')
            ->modalDescription(self::description())
            ->modalWidth('5xl')
            ->fillForm(fn (Box $record): array => self::fill(collect([$record->getKey()])))
            ->form(self::form())
            ->modalSubmitActionLabel('Return to RAS')
            ->authorize(fn (): bool => self::allowed())
            ->action(function (Box $record, array $data): void {
                self::perform(collect([$record->getKey()]), $data);
            });
    }

    public static function bulk(string $name = 'returnBoxesToRas'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Return to RAS')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->modalHeading('Return the selected boxes to RAS')
            ->modalDescription(self::description())
            ->modalWidth('5xl')
            ->fetchSelectedRecords(false)
            ->beforeFormFilled(function (Collection $records, BulkAction $action): void {
                if ($records->count() > self::MAX_BOXES) {
                    BoxBulkSupport::stop(
                        'Too many boxes selected for Return to RAS',
                        'Each box needs its new barcode typed in, so at most ' . self::MAX_BOXES . ' boxes can be returned at a time. '
                        . $records->count() . ' are selected.',
                    );
                    $action->cancel();
                }
            })
            ->fillForm(fn (Collection $records): array => self::fill($records))
            ->form(self::form())
            ->modalSubmitActionLabel('Return to RAS')
            ->authorize(fn (): bool => self::allowed())
            ->action(function (Collection $records, array $data): void {
                self::perform($records, $data);
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function allowed(): bool
    {
        // As ChangeBoxBatchAction: a box returned from NRA moves its documents
        // into the new batch, so changing documents is required too.
        $user = auth()->user();

        return $user !== null && $user->can('update_box') && $user->can('update_document');
    }

    private static function description(): string
    {
        return 'Each box gets a new barcode (the old one is kept in the barcode history), the location RAS and barcode status IN. '
            . 'A RAS box keeps its batch and box number. A box of another type (NRA, MUS, MAV, STVC…) becomes a RAS box in a new batch created for this movement.';
    }

    /* ---- Form ------------------------------------------------------------- */

    /**
     * @return array<int, mixed>
     */
    private static function form(): array
    {
        $anyOther = fn (Get $get): bool => collect((array) $get('boxes'))->contains(fn (mixed $row): bool => is_array($row) && empty($row['is_ras']));

        return [
            Hidden::make('batch_repository_id'),
            Repeater::make('boxes')
                ->label('Boxes')
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->itemLabel(fn (array $state): ?string => isset($state['label']) ? (string) $state['label'] : null)
                ->columns(3)
                ->schema([
                    Hidden::make('box_id'),
                    Hidden::make('is_ras'),
                    Hidden::make('label'),
                    TextInput::make('barcode')
                        ->label('New barcode')
                        ->required()
                        ->maxLength(64)
                        ->distinct()
                        ->validationMessages(['distinct' => 'The same barcode is typed for two boxes.'])
                        ->rule(static fn (Get $get): \Closure => static function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $message = self::barcodeProblem((string) $value, (int) $get('box_id'));
                            if ($message !== null) {
                                $fail($message);
                            }
                        }),
                    TextInput::make('box_number')
                        ->label('New box number (optional)')
                        ->helperText('Leave blank to keep its identifier.')
                        ->integer()
                        ->minValue(1)
                        ->maxLength(32)
                        ->distinct()
                        ->validationMessages(['distinct' => 'The same box number is typed for two boxes.'])
                        ->visible(fn (Get $get): bool => empty($get('is_ras'))),
                    Select::make('current_box_type')
                        ->label('Current Box Type')
                        ->options(fn (Get $get): array => CurrentBoxType::optionsWith(filled($get('current_box_type')) ? (string) $get('current_box_type') : null))
                        ->searchable()
                        ->nullable()
                        ->visible(fn (Get $get): bool => empty($get('is_ras'))),
                ]),
            Section::make('New batch for this movement')
                ->description('The boxes that are not RAS boxes join this batch, created as for an accession.')
                ->columns(3)
                ->visible($anyOther)
                ->schema([
                    TextInput::make('batch_number')
                        ->label('Batch number')
                        ->required($anyOther)
                        ->maxLength(64)
                        ->rule(static fn (Get $get): \Closure => static function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $repositoryId = self::allowedRepository($get('batch_repository_id'));
                            $message = self::batchNumberProblem((string) $value, $repositoryId);
                            if ($message !== null) {
                                $fail($message);
                            }
                        }),
                    Select::make('batch_type')
                        ->label('Accession type')
                        ->options(fn (): array => BatchType::optionsWith(self::DEFAULT_BATCH_TYPE))
                        ->required($anyOther),
                    TextInput::make('batch_description')
                        ->label('Description')
                        ->maxLength(255),
                ]),
            Textarea::make('note')
                ->label('Tracking note (optional)')
                ->rows(2)
                ->maxLength(500),
        ];
    }

    /**
     * One row per box, in the order of the selection; the batch number
     * suggested is the next free one of the boxes' repository.
     *
     * @param Collection<int, mixed> $keys
     * @return array<string, mixed>
     */
    private static function fill(Collection $keys): array
    {
        $boxes = Box::query()->with('batch')->whereKey($keys->take(self::MAX_BOXES)->all())->get()->keyBy(fn (Box $box): int => (int) $box->getKey());

        $rows = [];
        foreach ($keys->take(self::MAX_BOXES) as $key) {
            $box = $boxes->get((int) $key);
            if ($box === null) {
                continue;
            }
            $rows[] = [
                'box_id' => $box->getKey(),
                'is_ras' => $box->box_type === 'RAS',
                'label' => $box->shortLabel()
                    . (filled($box->barcode) ? ' · now ' . $box->barcode . ($box->box_type === 'RAS' ? ' ' . $box->barcode_status : '') : ''),
                'barcode' => null,
                'box_number' => null,
                'current_box_type' => $box->current_box_type,
            ];
        }

        $repositoryId = self::batchRepository($boxes->filter(fn (Box $box): bool => $box->box_type !== 'RAS')->values());

        return [
            'boxes' => $rows,
            'batch_repository_id' => $repositoryId,
            'batch_number' => $repositoryId !== null ? self::nextBatchNumber($repositoryId) : null,
            'batch_type' => self::DEFAULT_BATCH_TYPE,
            'batch_description' => 'Boxes returned from NRA to RAS on ' . DisplayDate::date(now()),
            'note' => null,
        ];
    }

    /** Why this barcode cannot be the box's new one, or null when it can. */
    private static function barcodeProblem(string $barcode, int $boxId): ?string
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return 'A new barcode is required.';
        }

        $holder = Box::withoutGlobalScopes()->where('barcode', $barcode)->value('id');
        if ($holder === null) {
            return null;
        }

        return (int) $holder === $boxId
            ? 'This is the box\'s current barcode: give it a new one.'
            : 'This barcode is already assigned to another box. Box barcodes must be globally unique.';
    }

    /**
     * The repository posted in the hidden field, only when the user may work
     * in it: a forged id must not reveal which batch numbers another
     * repository holds. The batch itself is planned from the boxes on the
     * server (batchPlan), never from this field.
     */
    private static function allowedRepository(mixed $id): ?int
    {
        $user = auth()->user();
        if (! filled($id) || $user === null) {
            return null;
        }
        $id = (int) $id;
        $allowed = ActiveRepository::allowedRepositoryIdsFor($user);

        return $allowed === null || in_array($id, array_map(intval(...), $allowed), true) ? $id : null;
    }

    /** Why this number cannot be the new batch, or null when it can. */
    private static function batchNumberProblem(string $number, ?int $repositoryId): ?string
    {
        $number = trim($number);
        if ($number === '') {
            return null;
        }
        if (! Batch::isAcceptableNumberFormat($number)) {
            return 'A numeric batch number must be a whole number of 1 or greater.';
        }
        $candidate = new Batch(['batch_number' => $number]);
        if ($candidate->isForbidden()) {
            return "Batch number {$number} is reserved/forbidden (RFQ rule).";
        }
        if ($candidate->isWillsOnly() || $candidate->isReservedMav()) {
            return "Batch {$number} is reserved (wills / old MAV boxes) and cannot be created for this movement.";
        }
        $taken = Batch::withoutGlobalScopes()->withTrashed()
            ->where('batch_number', $number)
            ->where('repository_id', $repositoryId)
            ->exists();

        return $taken ? "Batch number {$number} already exists in this repository (it may be in the trash)." : null;
    }

    /**
     * The repository the new batch is created in: the one the non-RAS boxes
     * belong to, else the active one. Null when they belong to more than one.
     *
     * @param Collection<int, Box> $others
     */
    private static function batchRepository(Collection $others): ?int
    {
        $ids = $others->map(fn (Box $box): ?int => $box->effectiveRepositoryId())->filter()->unique()->values();
        if ($ids->count() > 1) {
            return null;
        }

        $id = $ids->first() ?? app(ActiveRepository::class)->id() ?? auth()->user()?->default_repository_id;

        return $id !== null ? (int) $id : null;
    }

    private static function nextBatchNumber(int $repositoryId): string
    {
        $next = (int) Batch::withoutGlobalScopes()->withTrashed()
            ->where('repository_id', $repositoryId)
            ->pluck('batch_number')
            ->filter(static fn ($v): bool => ctype_digit((string) $v))
            ->map(static fn ($v): int => (int) $v)
            ->max() + 1;
        while (in_array($next, [...Batch::FORBIDDEN_NUMBERS, Batch::RESERVED_MAV_BATCH, Batch::WILLS_BATCH], true)) {
            $next++;
        }

        return (string) $next;
    }

    /* ---- Action ----------------------------------------------------------- */

    /**
     * @param Collection<int, mixed> $records
     * @param array<string, mixed> $data
     */
    private static function perform(Collection $records, array $data): void
    {
        $failed = 'No box was returned to RAS';

        if ($records->count() > self::MAX_BOXES) {
            BoxBulkSupport::stop($failed, 'At most ' . self::MAX_BOXES . ' boxes can be returned at a time.');

            return;
        }

        // The rows of the form, by box: only the selected boxes are read, so
        // a posted row for any other box is ignored.
        $rows = [];
        foreach ((array) ($data['boxes'] ?? []) as $row) {
            if (is_array($row) && isset($row['box_id'])) {
                $rows[(int) $row['box_id']] = $row;
            }
        }

        $targets = BoxBulkSupport::namedLocations($records, Location::NAME_RAS);
        if ($targets === null) {
            BoxBulkSupport::stop($failed, 'There is no active location named "' . Location::NAME_RAS . '" for these boxes. Add it under Locations and try again.');

            return;
        }

        // Through the scoped query: a box of another repository is not read.
        $others = Box::query()->whereKey($records->all())->where('box_type', '!=', 'RAS')->get();
        $batchPlan = null;
        if ($others->isNotEmpty()) {
            $batchPlan = self::batchPlan($others, $data);
            if (is_string($batchPlan)) {
                BoxBulkSupport::stop($failed, $batchPlan);

                return;
            }
        }

        $note = trim((string) ($data['note'] ?? ''));
        /** @var Batch|null $batch created with the first box that needs it */
        $batch = null;

        $result = BoxBulkSupport::run($records, static function (Box $box) use ($rows, $targets, $note, $batchPlan, &$batch): void {
            $row = $rows[(int) $box->getKey()] ?? null;
            $target = $targets[(int) $box->effectiveRepositoryId()];

            if ($box->box_type === 'RAS') {
                self::returnRasBox($box, $row, $target, $note);

                return;
            }

            if ($batchPlan === null) {
                throw new \DomainException('No batch for this box.');
            }
            // The batch is created inside the first box's transaction: if that
            // box is refused the batch goes with it, and the next box tries again.
            $createdHere = false;

            try {
                if ($batch === null) {
                    $batch = self::createBatch($batchPlan);
                    $createdHere = true;
                }
                self::returnOtherBox($box, $row, $target, $note, $batch);
            } catch (\Throwable $e) {
                if ($createdHere) {
                    $batch = null;
                }

                throw $e;
            }
        });

        $before = $batch !== null ? ["Batch {$batch->batch_number} was created for this movement."] : [];
        BoxBulkSupport::notify($result, 'returned to RAS', $failed, $before);
    }

    /**
     * What the new batch will be, or why it cannot be made.
     *
     * @param Collection<int, Box> $others
     * @param array<string, mixed> $data
     * @return array{number: string, type: string, description: ?string, repository_id: int}|string
     */
    private static function batchPlan(Collection $others, array $data): array|string
    {
        if (! (auth()->user()?->can('create_batch') ?? false)) {
            return 'Returning a box that is not a RAS box creates a new batch, and you may not create batches.';
        }

        $repositoryId = self::batchRepository($others);
        if ($repositoryId === null) {
            return 'The boxes that are not RAS boxes belong to more than one repository. Return them one repository at a time.';
        }

        $number = trim((string) ($data['batch_number'] ?? ''));
        if ($number === '') {
            return 'A batch number is required for the boxes that are not RAS boxes.';
        }
        $problem = self::batchNumberProblem($number, $repositoryId);
        if ($problem !== null) {
            return $problem;
        }

        $type = (string) ($data['batch_type'] ?? self::DEFAULT_BATCH_TYPE);
        if (! array_key_exists($type, BatchType::optionsWith(self::DEFAULT_BATCH_TYPE))) {
            return 'The accession type is not valid.';
        }

        $description = trim((string) ($data['batch_description'] ?? ''));

        return ['number' => $number, 'type' => $type, 'description' => $description !== '' ? $description : null, 'repository_id' => $repositoryId];
    }

    /**
     * @param array{number: string, type: string, description: ?string, repository_id: int} $plan
     */
    private static function createBatch(array $plan): Batch
    {
        // Checked again inside the transaction: another user may have taken it.
        $problem = self::batchNumberProblem($plan['number'], $plan['repository_id']);
        if ($problem !== null) {
            throw new \DomainException($problem);
        }

        return Batch::create([
            'batch_number' => $plan['number'],
            'type' => $plan['type'],
            'description' => $plan['description'],
            'repository_id' => $plan['repository_id'],
            'is_active' => true,
        ]);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function newBarcode(Box $box, ?array $row): string
    {
        $barcode = trim((string) ($row['barcode'] ?? ''));
        if ($barcode === '') {
            throw new \DomainException('No new barcode was given for this box.');
        }
        $problem = self::barcodeProblem($barcode, (int) $box->getKey());
        if ($problem !== null) {
            throw new \DomainException($problem);
        }

        return $barcode;
    }

    private static function refuseDestroyed(Box $box): void
    {
        if ($box->isDestroyed()) {
            throw new \DomainException('The box is destroyed.');
        }
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function returnRasBox(Box $box, ?array $row, Location $target, string $note): void
    {
        self::refuseDestroyed($box);
        if ($box->barcode_status === 'IN' && (int) $box->location_id === (int) $target->getKey()) {
            throw new \DomainException('Already at RAS with status IN.');
        }

        $box->barcode = self::newBarcode($box, $row);
        $box->barcode_status = 'IN';
        $box->location_id = $target->getKey();
        $box->keepLocationWhenIn = true;
        $box->locationChangeSource = self::LOCATION_SOURCE;
        $box->barcodeChangeReason = 'Returned to RAS';
        if ($note !== '') {
            $box->appendTrackingNote('[Return to RAS] ' . $note);
        }
        $box->save();

        BoxBulkSupport::documentsFollowBox($box);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function returnOtherBox(Box $box, ?array $row, Location $target, string $note, Batch $batch): void
    {
        self::refuseDestroyed($box);
        if ((int) $box->effectiveRepositoryId() !== (int) $batch->repository_id) {
            throw new \DomainException('The box belongs to a different repository than the new batch.');
        }

        $was = trim($box->box_type . ' ' . $box->box_number . (filled($box->barcode) ? ', barcode ' . $box->barcode : ''));
        $number = trim((string) ($row['box_number'] ?? ''));
        $currentBoxType = isset($row['current_box_type']) && filled($row['current_box_type']) ? (string) $row['current_box_type'] : null;

        $box->barcode = self::newBarcode($box, $row);
        $box->barcode_status = 'IN';
        $box->box_type = 'RAS';
        if ($number !== '') {
            $box->box_number = $number;
        }
        if ($currentBoxType !== null && $currentBoxType !== $box->current_box_type) {
            if (! array_key_exists($currentBoxType, CurrentBoxType::optionsWith($box->current_box_type))) {
                throw new \DomainException('The Current Box Type is not valid.');
            }
            $box->current_box_type = $currentBoxType;
        }
        // Each batch numbers its boxes on its own (Box::buildSortQuery()).
        $box->sort_order = (int) Box::withoutGlobalScopes()->where('batch_id', $batch->getKey())->max('sort_order') + 1;
        $box->batch_id = $batch->getKey();
        $box->location_id = $target->getKey();
        $box->keepLocationWhenIn = true;
        $box->locationChangeSource = self::LOCATION_SOURCE;
        $box->barcodeChangeReason = "Returned to RAS from {$was}";
        $box->appendTrackingNote('[Return to RAS] Was ' . $was . ($note !== '' ? ': ' . $note : ''));
        $box->save();

        // As with Change batch: every document of the box belongs to its batch.
        $moved = Document::withoutGlobalScopes()
            ->where('current_box_id', $box->getKey())
            ->where(static fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', '!=', $batch->getKey()))
            ->toBase()
            ->get(['id', 'batch_id']);
        Document::withoutGlobalScopes()
            ->where('current_box_id', $box->getKey())
            ->update(['batch_id' => $batch->getKey()]);
        BoxBulkSupport::auditDocuments(
            $moved->map(static fn (object $document): array => [
                'id' => (int) $document->id,
                'old' => ['batch_id' => $document->batch_id],
                'new' => ['batch_id' => $batch->getKey()],
            ])->values()->all(),
            'document.moved_with_box',
            'batch,document',
        );

        BoxBulkSupport::documentsFollowBox($box);
    }
}
