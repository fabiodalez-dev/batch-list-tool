<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use App\Models\Pivots\AccessionBatch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Accession extends Model implements AuditableContract, HasMedia
{
    use Auditable;
    use BelongsToRepository;
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;

    /**
     * `repository_id` is mass-assignable so Filament admins can write it via
     * `create()` — but the BelongsToRepository `creating` hook is the security
     * gate: it validates the value against the user's pivot and throws for
     * any non-privileged attempt to write to a foreign tenant.
     *
     * @see BelongsToRepository
     */
    protected $fillable = [
        'code', 'accession_number', 'accession_date', 'authority_id', 'repository_id', 'notes',
    ];

    protected $casts = [
        'accession_date' => 'date',
    ];

    public function authority(): BelongsTo
    {
        return $this->belongsTo(Authority::class);
    }

    public function batches(): BelongsToMany
    {
        // F041 — ->using() wires the AccessionBatch pivot model so its
        // same-repository creating() guard fires on every attach/sync.
        return $this->belongsToMany(Batch::class, 'accession_batch')
            ->using(AccessionBatch::class)
            ->withTimestamps();
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Feedback1 — client request: "Can we have attachments (pdfs) – multiple
     * – Digriet/Conservation Report/Emails" on accessions.
     *
     * Mirrors the Document `attachments` media collection exactly (same
     * accepted mime list). image/tif AND image/tiff: RFC 3302 lists both as
     * valid; some Windows tools + iOS Files app emit image/tif. Accept both
     * so a legitimate scan isn't silently rejected at upload time.
     */
    public function registerMediaCollections(): void
    {
        // F032 — store on the private `media` disk (no public /storage URL).
        // Files are reachable only through the authenticated, policy-checked
        // `attachments.download` route — never world-readable.
        $this->addMediaCollection('attachments')
            ->useDisk('media')
            ->acceptsMimeTypes([
                'application/pdf',
                'image/jpeg', 'image/png', 'image/tiff', 'image/tif',
            ]);
    }
}
