<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only row model backed by StockTakeReport's UNION subquery.
 *
 * There is no physical `stock_take_entries` table; the report aliases a
 * subquery to this table name so Filament can render box/document stock-take
 * detail rows with normal Eloquent table semantics.
 */
class StockTakeEntry extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'stock_take_entries';

    protected $primaryKey = 'row_key';

    protected $keyType = 'int';

    protected $guarded = [];
}
