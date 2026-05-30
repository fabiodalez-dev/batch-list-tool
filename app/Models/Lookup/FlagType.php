<?php

namespace App\Models\Lookup;

use App\Models\DocumentFlag;
use App\Models\Lookup\Concerns\HasLookupOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Controlled vocabulary for document flag types (RFQ §3.1.11 / §3.1.12).
 * Seeded from {@see DocumentFlag::TYPES}; `colour` mapped from the
 * inverted {@see DocumentFlag::COLOUR_TYPES} (legacy colour-coding).
 */
class FlagType extends Model
{
    use HasLookupOptions;

    protected $table = 'flag_types';

    protected $fillable = ['code', 'label', 'sort_order', 'is_active', 'metadata', 'colour'];

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
