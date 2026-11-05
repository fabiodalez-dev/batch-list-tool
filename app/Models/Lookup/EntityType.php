<?php

namespace App\Models\Lookup;

use App\Models\Lookup\Concerns\HasLookupOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Controlled vocabulary for an authority's Type of Entity.
 *
 * Client 2026-11-05: "Currently a dropdown, from where can we edit the options?
 * Person is not currently an option." `code` is what authorities.entity_type
 * stores.
 */
class EntityType extends Model implements AuditableContract
{
    use Auditable;
    use HasLookupOptions;

    /** What a new authority starts as (feedback 1, 2026-06-06), while active. */
    public const string DEFAULT_CODE = 'Notary';

    protected $table = 'entity_types';

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

    /**
     * Every value, inactive too: how a stored code reads on screen, and what a
     * list rule can look for.
     *
     * @return array<string,string> code=>label
     */
    public static function labels(): array
    {
        return static::query()->orderBy('sort_order')->pluck('label', 'code')->all();
    }

    public static function defaultCode(): ?string
    {
        return array_key_exists(self::DEFAULT_CODE, static::options()) ? self::DEFAULT_CODE : null;
    }
}
