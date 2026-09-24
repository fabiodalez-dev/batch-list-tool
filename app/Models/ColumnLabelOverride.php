<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ColumnLabels\ColumnLabels;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A renamed built-in column.
 *
 * One row means "in this repository, call this field that instead". The field
 * key, its type and its validation rules stay in code; only the label moves —
 * which is what makes handing the rename over to the cataloguer safe.
 *
 * Audited, because a column that changes name changes what every downloaded
 * template looks like, and "who renamed this and when" is the first question
 * asked when a sheet stops matching.
 */
class ColumnLabelOverride extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'repository_id',
        'entity_type',
        'field_key',
        'label',
    ];

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * Refuse a name another column in the same repository already carries.
     *
     * The form says so in words, but the guard lives here as well: a clash
     * reaching the database through a seeder, a console command or a future
     * bulk edit would produce two identical headers in the template, and the
     * importer would keep only one of them — a column filled in by hand and
     * then discarded without a message. There is no good reason to allow it
     * from any direction.
     */
    protected static function booted(): void
    {
        static::saving(function (self $override): void {
            $repositoryId = $override->repository_id !== null ? (int) $override->repository_id : null;

            if (ColumnLabels::clashesWith($override->entity_type, $override->field_key, (string) $override->label, $repositoryId)) {
                throw ValidationException::withMessages([
                    'label' => sprintf(
                        'Another column in this repository is already called "%s". Two columns with the same name cannot be told apart on the spreadsheet.',
                        trim((string) $override->label),
                    ),
                ]);
            }
        });
    }
}
