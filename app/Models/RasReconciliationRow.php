<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the RAS reconciliation: a row of the RAS export, or one of our
 * RAS boxes that the export does not list, with the outcome of the comparison.
 *
 * @property int $id
 * @property int $run_id
 * @property int|null $sheet_row
 * @property string|null $ras_barcode
 * @property string|null $ras_reference
 * @property string|null $ras_batch
 * @property string|null $ras_box
 * @property string|null $ras_status_raw
 * @property string|null $ras_status
 * @property Carbon|null $ras_date
 * @property string|null $ras_container
 * @property int|null $box_id
 * @property string|null $our_barcode
 * @property bool $our_barcode_replaced
 * @property string|null $our_batch
 * @property string|null $our_box
 * @property string|null $our_status
 * @property string $outcome
 * @property bool $status_mismatch
 * @property string|null $note
 */
class RasReconciliationRow extends Model
{
    public const string RECONCILED = 'reconciled';

    public const string STATUS_DIFFERS = 'status_differs';

    public const string BARCODE_DIFFERS = 'barcode_differs';

    public const string BATCH_BOX_DIFFERS = 'batch_box_differs';

    /**
     * RAS lists an earlier barcode of our box (our box's own barcode is on the
     * report too) that the box's barcode history does not record.
     */
    public const string OLD_BARCODE_MISSING = 'old_barcode_missing';

    public const string ONLY_IN_RAS = 'only_in_ras';

    public const string ONLY_IN_OURS = 'only_in_ours';

    /** Outcome => label, in the order the report shows them. */
    public const array OUTCOMES = [
        self::RECONCILED => 'Reconciled',
        self::STATUS_DIFFERS => 'Status differs',
        self::BARCODE_DIFFERS => 'Barcode differs',
        self::BATCH_BOX_DIFFERS => 'Batch / box differs',
        self::OLD_BARCODE_MISSING => 'Earlier barcode not in our history',
        self::ONLY_IN_RAS => 'Only in RAS',
        self::ONLY_IN_OURS => 'Only in our system',
    ];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'ras_date' => 'date',
        'our_barcode_replaced' => 'boolean',
        'status_mismatch' => 'boolean',
    ];

    /** @return BelongsTo<RasReconciliationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RasReconciliationRun::class, 'run_id');
    }

    public function outcomeLabel(): string
    {
        return self::OUTCOMES[$this->outcome] ?? $this->outcome;
    }

    /** "ARK/054-4" style key of ours, for display next to RAS's. */
    public function ourReference(): ?string
    {
        if ($this->our_box === null && $this->our_batch === null) {
            return null;
        }

        return sprintf('Batch %s, box %s', $this->our_batch ?? '—', $this->our_box ?? '—');
    }
}
