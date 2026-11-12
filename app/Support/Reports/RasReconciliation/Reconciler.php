<?php

declare(strict_types=1);

namespace App\Support\Reports\RasReconciliation;

use App\Models\Box;
use App\Models\RasReconciliationRow as Row;
use App\Models\RasReconciliationRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Compares the RAS export with our RAS boxes (client 2026-11-10, Report 1).
 *
 * RAS lists containers by barcode. When a box is moved to a new container RAS
 * keeps the old barcode as "Perm Out" and adds the new one, so the same
 * ARK/box-batch shows up on two or three rows. We keep one box per batch/box,
 * with its current barcode, and the barcodes it had before in
 * box_barcode_history. Each RAS row is therefore matched like this:
 *
 *  1. its barcode is the current barcode of one of our boxes, or
 *  2. its barcode is an earlier barcode of one of our boxes (our status for
 *     it is the one it had when it was replaced), then the batch/box and the
 *     status are compared: all equal → Reconciled; otherwise "Batch / box
 *     differs" or "Status differs";
 *  3. the barcode is unknown to us but one of our boxes has that batch/box →
 *     "Barcode differs"; when that box's own barcode is on the RAS report
 *     too, the RAS barcode is an earlier one our barcode history does not
 *     record → "Earlier barcode not in our history";
 *  4. nothing → "Only in RAS".
 *
 * Our RAS boxes whose current barcode RAS does not list, and that no
 * "Barcode differs" row already points at, are added as "Only in our system".
 *
 * The rows are stored with the run; the report page only reads them. Our
 * boxes are held in memory as a small index while comparing (about 7,000 RAS
 * boxes); the RAS rows are streamed in and written in chunks.
 *
 * @phpstan-type OurIndex array{
 *     boxes: array<int, array{barcode: ?string, batch: ?string, box: ?string, key: ?string, status: ?string}>,
 *     byBarcode: array<string, int>,
 *     byKey: array<string, int>,
 *     oldBarcodes: array<string, array{box_id: int, status: ?string, now: ?string}>
 * }
 * @phpstan-type Judgement array{box_id: ?int, our_barcode: ?string, our_barcode_replaced: bool, our_batch: ?string, our_box: ?string, our_status: ?string, outcome: string, status_mismatch: bool, note: ?string}
 */
final class Reconciler
{
    private const int CHUNK = 500;

    /** The columns a comparison (re)writes. */
    private const array OUR_SIDE = [
        'box_id', 'our_barcode', 'our_barcode_replaced', 'our_batch', 'our_box', 'our_status',
        'outcome', 'status_mismatch', 'note',
    ];

    /**
     * Read the upload into a new run, compare it, and drop the previous run of
     * the same repository.
     *
     * @throws \RuntimeException when the file is not a RAS export
     */
    public function upload(string $path, string $extension, string $originalName, ?int $repositoryId, ?int $userId): RasReconciliationRun
    {
        $run = DB::transaction(function () use ($path, $extension, $originalName, $repositoryId, $userId): RasReconciliationRun {
            $run = RasReconciliationRun::create([
                'repository_id' => $repositoryId,
                'user_id' => $userId,
                'original_filename' => mb_substr($originalName, 0, 255),
            ]);
            $index = $this->ourIndex($run);

            $buffer = [];
            $count = 0;
            foreach (RasExport::rows($path, $extension) as $sheetRow => $cells) {
                $key = RasExport::parseReference($cells['reference']);
                $ras = [
                    'run_id' => $run->id,
                    'sheet_row' => $sheetRow,
                    'ras_barcode' => self::cut(RasExport::normaliseBarcode($cells['barcode']), 64),
                    'ras_reference' => self::cut(self::text($cells['reference']), 64),
                    'ras_batch' => $key['batch'] ?? null,
                    'ras_box' => $key['box'] ?? null,
                    'ras_status_raw' => self::cut(self::text($cells['status']), 32),
                    'ras_status' => RasExport::mapStatus($cells['status']),
                    'ras_date' => RasExport::parseDate($cells['date']),
                    'ras_container' => self::cut(self::text($cells['container']), 32),
                ];
                $buffer[] = $ras + $this->judge($ras, $index);
                $count++;
                if (count($buffer) >= self::CHUNK) {
                    DB::table('ras_reconciliation_rows')->insert($buffer);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                DB::table('ras_reconciliation_rows')->insert($buffer);
            }
            if ($count === 0) {
                throw new \RuntimeException('The file has a header but no data rows.');
            }

            $run->ras_rows = $count;
            $this->finish($run, $index);

            return $run;
        });

        // Only the latest upload per repository is kept.
        RasReconciliationRun::query()
            ->forScope($repositoryId, $userId)
            ->whereKeyNot($run->id)
            ->get()
            ->each(fn (RasReconciliationRun $old) => $old->delete());

        return $run;
    }

    /**
     * Compare the stored RAS rows again with our boxes as they are now.
     */
    public function compare(RasReconciliationRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $index = $this->ourIndex($run);

            DB::table('ras_reconciliation_rows')->where('run_id', $run->id)->where('outcome', Row::ONLY_IN_OURS)->delete();

            DB::table('ras_reconciliation_rows')
                ->where('run_id', $run->id)
                ->select(['id', 'run_id', 'sheet_row', 'ras_barcode', 'ras_reference', 'ras_batch', 'ras_box', 'ras_status_raw', 'ras_status', 'ras_date', 'ras_container'])
                ->chunkById(self::CHUNK, function ($rows) use ($index): void {
                    $updates = [];
                    foreach ($rows as $row) {
                        // upsert() inserts whole rows: the RAS side goes back as it was.
                        /** @var array{ras_barcode: ?string, ras_batch: ?string, ras_box: ?string, ras_status: ?string, ras_status_raw: ?string} $ras */
                        $ras = (array) $row;
                        $updates[] = $ras + $this->judge($ras, $index);
                    }
                    DB::table('ras_reconciliation_rows')->upsert($updates, ['id'], self::OUR_SIDE);
                });

            $this->finish($run, $index);
        });
    }

    /**
     * Count of rows per outcome, plus the rows whose status differs whatever
     * their outcome ("status_mismatch").
     *
     * @return array<string, int>
     */
    public static function summary(RasReconciliationRun $run): array
    {
        $counts = array_fill_keys(array_keys(Row::OUTCOMES), 0);
        $grouped = DB::table('ras_reconciliation_rows')
            ->where('run_id', $run->id)
            ->selectRaw('outcome, count(*) as n')
            ->groupBy('outcome')
            ->pluck('n', 'outcome');
        foreach ($grouped as $outcome => $n) {
            $counts[(string) $outcome] = (int) $n;
        }
        $counts['status_mismatch'] = DB::table('ras_reconciliation_rows')->where('run_id', $run->id)->where('status_mismatch', true)->count();

        return $counts;
    }

    /**
     * Our RAS boxes in the run's repository, as the comparison sees them.
     *
     * @return Builder<Box>
     */
    public static function ourBoxes(RasReconciliationRun $run): Builder
    {
        $query = Box::query()->where('boxes.box_type', 'RAS');

        if ($run->repository_id !== null) {
            $repositoryId = $run->repository_id;
            $query->where(function (Builder $q) use ($repositoryId): void {
                $q->whereIn('boxes.batch_id', fn (QueryBuilder $b) => $b->select('id')->from('batches')->where('repository_id', $repositoryId))
                    ->orWhere(fn (Builder $own) => $own->whereNull('boxes.batch_id')->where('boxes.repository_id', $repositoryId));
            });
        }

        return $query;
    }

    /**
     * After the RAS rows are judged: separate the earlier barcodes missing
     * from our history, add our boxes RAS does not list, stamp the run.
     *
     * @param OurIndex $index
     */
    private function finish(RasReconciliationRun $run, array $index): void
    {
        $this->separateMissingOldBarcodes($run);
        $this->addOnlyInOurs($run);

        $run->our_boxes = count($index['boxes']);
        $run->compared_at = now();
        $run->save();
    }

    /**
     * @return OurIndex
     */
    private function ourIndex(RasReconciliationRun $run): array
    {
        $boxes = [];
        $byBarcode = [];
        $byKey = [];

        $query = self::ourBoxes($run)
            ->leftJoin('batches', 'batches.id', '=', 'boxes.batch_id')
            ->select(['boxes.id', 'boxes.box_number', 'boxes.barcode', 'boxes.barcode_status', 'batches.batch_number']);

        foreach ($query->toBase()->lazyById(1000, 'boxes.id', 'id') as $box) {
            $batch = $box->batch_number === null ? null : (string) $box->batch_number;
            $number = $box->box_number === null ? null : (string) $box->box_number;
            $nBatch = RasExport::normaliseBatch($batch);
            $nBox = RasExport::normaliseBox($number);
            $key = $nBatch !== null && $nBox !== null ? $nBatch . '|' . $nBox : null;
            $barcode = RasExport::normaliseBarcode($box->barcode);

            $id = (int) $box->id;
            $boxes[$id] = [
                'barcode' => $box->barcode === null ? null : (string) $box->barcode,
                'batch' => $batch,
                'box' => $number,
                'key' => $key,
                'status' => $box->barcode_status === null ? null : (string) $box->barcode_status,
            ];
            if ($barcode !== null) {
                $byBarcode[$barcode] ??= $id;
            }
            if ($key !== null) {
                $byKey[$key] ??= $id;
            }
        }

        // Earlier barcodes. The status a barcode had when it was replaced is
        // the one RAS should still show for it (normally Perm Out).
        $oldBarcodes = [];
        if ($boxes !== []) {
            $history = DB::table('box_barcode_history')
                ->whereIn('box_id', self::ourBoxes($run)->select('boxes.id')->toBase())
                ->whereNotNull('previous_barcode')
                ->select(['id', 'box_id', 'previous_barcode', 'previous_status']);
            foreach ($history->lazyById(1000) as $h) {
                $barcode = RasExport::normaliseBarcode($h->previous_barcode);
                if ($barcode === null || isset($byBarcode[$barcode]) || ! isset($boxes[(int) $h->box_id])) {
                    continue;
                }
                $oldBarcodes[$barcode] = [
                    'box_id' => (int) $h->box_id,
                    'status' => $h->previous_status === null ? null : (string) $h->previous_status,
                    'now' => $boxes[(int) $h->box_id]['barcode'],
                ];
            }
        }

        return ['boxes' => $boxes, 'byBarcode' => $byBarcode, 'byKey' => $byKey, 'oldBarcodes' => $oldBarcodes];
    }

    /**
     * @param array{ras_barcode: ?string, ras_batch: ?string, ras_box: ?string, ras_status: ?string, ras_status_raw: ?string} $ras
     * @param OurIndex $index
     * @return Judgement
     */
    private function judge(array $ras, array $index): array
    {
        $barcode = $ras['ras_barcode'];
        $key = $ras['ras_batch'] !== null && $ras['ras_box'] !== null ? $ras['ras_batch'] . '|' . $ras['ras_box'] : null;
        $notes = [];
        if ($key === null) {
            $notes[] = 'RAS reference not readable as ARK/box-batch';
        }
        if ($ras['ras_status'] === null) {
            $notes[] = 'RAS status "' . ($ras['ras_status_raw'] ?? '') . '" not recognised';
        }

        $boxId = null;
        $replaced = false;
        $ourStatus = null;
        if ($barcode !== null && isset($index['byBarcode'][$barcode])) {
            $boxId = $index['byBarcode'][$barcode];
            $ourStatus = $index['boxes'][$boxId]['status'];
        } elseif ($barcode !== null && isset($index['oldBarcodes'][$barcode])) {
            $old = $index['oldBarcodes'][$barcode];
            $boxId = $old['box_id'];
            $ourStatus = $old['status'];
            $replaced = true;
            $notes[] = 'Earlier barcode of our box; its barcode now is ' . ($old['now'] ?? 'empty');
        }

        if ($boxId !== null) {
            $box = $index['boxes'][$boxId];
            $keyMatches = $key !== null && $key === $box['key'];
            $statusMatches = $ras['ras_status'] !== null && $ras['ras_status'] === $ourStatus;

            return [
                'box_id' => $boxId,
                'our_barcode' => $replaced ? $barcode : $box['barcode'],
                'our_barcode_replaced' => $replaced,
                'our_batch' => $box['batch'],
                'our_box' => $box['box'],
                'our_status' => $ourStatus,
                'outcome' => match (true) {
                    ! $keyMatches => Row::BATCH_BOX_DIFFERS,
                    ! $statusMatches => Row::STATUS_DIFFERS,
                    default => Row::RECONCILED,
                },
                'status_mismatch' => ! $statusMatches,
                'note' => self::note($notes),
            ];
        }

        if ($key !== null && isset($index['byKey'][$key])) {
            $boxId = $index['byKey'][$key];
            $box = $index['boxes'][$boxId];
            $notes[] = $box['barcode'] === null
                ? 'Our box for this batch/box has no barcode'
                : 'Our box for this batch/box has barcode ' . $box['barcode'];

            return [
                'box_id' => $boxId,
                'our_barcode' => $box['barcode'],
                'our_barcode_replaced' => false,
                'our_batch' => $box['batch'],
                'our_box' => $box['box'],
                'our_status' => $box['status'],
                'outcome' => Row::BARCODE_DIFFERS,
                'status_mismatch' => false,
                'note' => self::note($notes),
            ];
        }

        return [
            'box_id' => null,
            'our_barcode' => null,
            'our_barcode_replaced' => false,
            'our_batch' => null,
            'our_box' => null,
            'our_status' => null,
            'outcome' => Row::ONLY_IN_RAS,
            'status_mismatch' => false,
            'note' => self::note($notes),
        ];
    }

    /**
     * A "Barcode differs" row whose box's own barcode is ALSO on the RAS
     * report: RAS has an earlier barcode of the box that our barcode history
     * does not record — typically a "Perm Out" container the box has left.
     */
    private function separateMissingOldBarcodes(RasReconciliationRun $run): void
    {
        $runId = $run->id;
        $ids = DB::table('ras_reconciliation_rows as r')
            ->where('r.run_id', $runId)
            ->where('r.outcome', Row::BARCODE_DIFFERS)
            ->whereNotNull('r.our_barcode')
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')
                ->from('ras_reconciliation_rows as r2')
                ->where('r2.run_id', $runId)
                ->whereRaw('r2.ras_barcode = UPPER(TRIM(r.our_barcode))'))
            ->pluck('r.id')
            ->all();

        foreach (array_chunk($ids, 1000) as $chunk) {
            DB::table('ras_reconciliation_rows')->whereIn('id', $chunk)->update(['outcome' => Row::OLD_BARCODE_MISSING]);
        }
    }

    /**
     * Our boxes RAS does not list: their current barcode is on no RAS row, and
     * no "Barcode differs" row already points at them.
     */
    private function addOnlyInOurs(RasReconciliationRun $run): void
    {
        $runId = $run->id;
        $query = self::ourBoxes($run)
            ->leftJoin('batches', 'batches.id', '=', 'boxes.batch_id')
            ->where(function (Builder $q) use ($runId): void {
                $q->whereNull('boxes.barcode')
                    ->orWhereNotExists(fn (QueryBuilder $r) => $r->selectRaw('1')
                        ->from('ras_reconciliation_rows as r')
                        ->where('r.run_id', $runId)
                        ->whereRaw('r.ras_barcode = UPPER(TRIM(boxes.barcode))'));
            })
            ->whereNotExists(fn (QueryBuilder $r) => $r->selectRaw('1')
                ->from('ras_reconciliation_rows as r')
                ->where('r.run_id', $runId)
                ->where('r.outcome', Row::BARCODE_DIFFERS)
                ->whereColumn('r.box_id', 'boxes.id'))
            ->select(['boxes.id', 'boxes.box_number', 'boxes.barcode', 'boxes.barcode_status', 'batches.batch_number']);

        // Boxes RAS lists under an earlier barcode only.
        $underOldBarcode = array_fill_keys(
            DB::table('ras_reconciliation_rows')
                ->where('run_id', $runId)
                ->where('our_barcode_replaced', true)
                ->whereNotNull('box_id')
                ->distinct()
                ->pluck('box_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            true,
        );

        $buffer = [];
        foreach ($query->toBase()->lazyById(1000, 'boxes.id', 'id') as $box) {
            $id = (int) $box->id;
            $buffer[] = [
                'run_id' => $runId,
                'box_id' => $id,
                'our_barcode' => $box->barcode,
                'our_barcode_replaced' => false,
                'our_batch' => $box->batch_number === null ? null : (string) $box->batch_number,
                'our_box' => $box->box_number === null ? null : (string) $box->box_number,
                'our_status' => $box->barcode_status,
                'outcome' => Row::ONLY_IN_OURS,
                'status_mismatch' => false,
                'note' => match (true) {
                    isset($underOldBarcode[$id]) => 'RAS lists this box only under an earlier barcode',
                    $box->barcode === null => 'Our box has no barcode',
                    default => null,
                },
            ];
            if (count($buffer) >= self::CHUNK) {
                DB::table('ras_reconciliation_rows')->insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            DB::table('ras_reconciliation_rows')->insert($buffer);
        }
    }

    /** @param array<int, string> $notes */
    private static function note(array $notes): ?string
    {
        return $notes === [] ? null : mb_substr(implode('. ', $notes), 0, 255);
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
