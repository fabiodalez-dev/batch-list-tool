<?php

namespace App\Models\Lookup;

use App\Models\Box;
use App\Models\Lookup\Concerns\HasLookupOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Controlled vocabulary for box types (RFQ §3.1.11).
 * Seeded from {@see Box::TYPES}; `is_legacy` from
 * {@see Box::LEGACY_TYPES} (MAV / STVC).
 */
class BoxType extends Model implements AuditableContract
{
    use Auditable;
    use HasLookupOptions;

    protected $table = 'box_types';

    protected $fillable = ['code', 'label', 'sort_order', 'is_active', 'metadata', 'is_legacy'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_legacy' => 'boolean',
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

    /**
     * Options for FILTERING boxes, as opposed to assigning a type: every type
     * in the lookup (inactive and legacy included, since boxes of those types
     * still exist and must stay findable, e.g. to delete them) plus any type a
     * box actually carries that the lookup no longer lists, archived boxes
     * included. Falls back to {@see Box::TYPES} before the lookup is seeded.
     *
     * @return array<string,string> code => label
     */
    public static function filterOptions(): array
    {
        $options = static::query()->orderBy('sort_order')->orderBy('code')->pluck('label', 'code')->all();

        if ($options === []) {
            $options = array_combine(Box::TYPES, Box::TYPES);
        }

        $inUse = Box::withTrashed()->whereNotNull('box_type')->where('box_type', '!=', '')
            ->distinct()->orderBy('box_type')->pluck('box_type');

        foreach ($inUse as $code) {
            $options[$code] ??= $code;
        }

        return $options;
    }
}
