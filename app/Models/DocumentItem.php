<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NAF Queries Q5 — one itemised entry of a document.
 *
 * A document that stands for many physical items ("71 folders") is expanded
 * into one {@see DocumentItem} per folder. `position` preserves ordering,
 * `reference` is the folder number/label, `description` is free text.
 */
class DocumentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'position',
        'reference',
        'description',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
