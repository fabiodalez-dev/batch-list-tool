<?php

namespace App\Models\Lookup;

use App\Models\DocumentFlag;
use App\Models\Lookup\Concerns\HasLookupOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Controlled vocabulary for document flag types (RFQ §3.1.11 / §3.1.12).
 * Seeded from {@see DocumentFlag::TYPES}; `colour` mapped from the
 * inverted {@see DocumentFlag::COLOUR_TYPES} (legacy colour-coding).
 */
class FlagType extends Model implements AuditableContract
{
    use Auditable;
    use HasLookupOptions;

    /**
     * The colour names typed on the Flag Types page, as CSS colours.
     *
     * @var array<string, string>
     */
    public const CSS_COLOURS = [
        'red' => '#dc2626', 'pink' => '#db2777', 'orange' => '#ea580c', 'yellow' => '#ca8a04',
        'brown' => '#92400e', 'grey' => '#6b7280', 'gray' => '#6b7280', 'green' => '#16a34a',
        'blue' => '#2563eb', 'purple' => '#9333ea', 'black' => '#111827',
    ];

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

    /**
     * The CSS colour of a colour typed on the Flag Types page: a name from
     * {@see CSS_COLOURS} or a hex code ("#c0392b"). Null when blank or unknown.
     */
    public static function cssColour(?string $colour): ?string
    {
        $colour = strtolower(trim((string) $colour));
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $colour) === 1) {
            return $colour;
        }

        return self::CSS_COLOURS[$colour] ?? null;
    }

    /**
     * Every flag type's CSS colour, those without one left out.
     *
     * @return array<string, string> code => CSS colour
     */
    public static function cssColours(): array
    {
        return static::query()->whereNotNull('colour')->pluck('colour', 'code')
            ->map(fn (string $colour): ?string => self::cssColour($colour))
            ->filter()
            ->all();
    }
}
