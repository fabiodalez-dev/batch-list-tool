<?php

namespace App\Models\Lookup;

use App\Models\Lookup\Concerns\HasLookupOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Controlled vocabulary for batch types (RFQ §3.1.11).
 * Seeded from the `batches.type` enum (MAIN_COLLECTION / NOTARY_ACCESSION).
 */
class BatchType extends Model
{
    use HasLookupOptions;

    protected $table = 'batch_types';

    protected $fillable = ['code', 'label', 'sort_order', 'is_active', 'metadata'];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * @return array<string,string> code=>label of active values
     */
    public static function options(): array
    {
        return static::active()->pluck('label', 'code')->all();
    }
}
