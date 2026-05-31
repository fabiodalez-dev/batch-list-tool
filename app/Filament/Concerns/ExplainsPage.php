<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\PagePurposes;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Renders a collapsible "About this page" card (explaining the page's role in
 * the NRA Batch List workflow, per RFQ-2026-06 Appendix 1) into the Filament
 * page subheading slot.
 *
 * Usage: `use ExplainsPage;` on any Filament Page / Resource list page, and add
 * the matching entry to App\Support\PagePurposes keyed by the page class. A
 * page with no registry entry keeps its normal (usually empty) subheading.
 *
 * The text is resolved by the page's own class name, so the same trait works
 * for resource index pages and standalone custom pages alike.
 */
trait ExplainsPage
{
    public function getSubheading(): string|Htmlable|null
    {
        $purpose = PagePurposes::for(static::class);

        if ($purpose === null) {
            return null;
        }

        return new HtmlString(
            view('filament.components.about-page', [
                'body' => $purpose['body'],
                'refs' => $purpose['refs'] ?? '',
            ])->render()
        );
    }
}
