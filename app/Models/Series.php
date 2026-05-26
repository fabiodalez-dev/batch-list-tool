<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Series extends Model implements AuditableContract, Sortable
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;
    use SortableTrait;

    /**
     * Series are globally ordered — buildSortQuery() default is fine.
     */
    public array $sortable = [
        'order_column_name' => 'sort_order',
        'sort_when_creating' => true,
    ];

    protected $table = 'series';

    protected $fillable = ['sort_order', 'code', 'title', 'description', 'is_wills_series', 'is_active'];

    protected $casts = [
        'is_wills_series' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
