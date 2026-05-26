<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Series extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'series';

    protected $fillable = ['code', 'title', 'description', 'is_wills_series', 'is_active'];

    protected $casts = [
        'is_wills_series' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
