<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's column order and hidden columns for one list.
 *
 * @property int $id
 * @property int $user_id
 * @property string $table_key
 * @property array<int, array<string, mixed>> $columns
 * @property bool $has_reordered
 */
class UserTablePreference extends Model
{
    protected $fillable = ['user_id', 'table_key', 'columns', 'has_reordered'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'has_reordered' => 'boolean',
        ];
    }
}
