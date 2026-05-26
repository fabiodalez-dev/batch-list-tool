<?php

namespace App\Support\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;

/**
 * Strict CSP — no external CDN dependencies at runtime.
 *
 * Fonts:    served from /fonts/inter/        (rsms/inter v4.1, OFL-1.1)
 * Avatars:  generated server-side as inline SVG (laravolt/avatar)
 * Icons:    Filament heroicons are inline SVG (no network call)
 *
 * Filament 3 + Livewire 3 + Alpine.js heavily use inline styles/scripts/eval;
 * we accept `unsafe-inline` + `unsafe-eval` and disable Spatie's nonce
 * generation in .env (CSP_NONCE_ENABLED=false) so the relaxation takes effect.
 */
class AppPolicy implements Preset
{
    public function configure(Policy $policy): void
    {
        $policy
            ->add(Directive::BASE, Keyword::SELF)
            ->add(Directive::DEFAULT, Keyword::SELF)
            ->add(Directive::FORM_ACTION, Keyword::SELF)
            ->add(Directive::FRAME_ANCESTORS, Keyword::NONE)
            ->add(Directive::OBJECT, Keyword::NONE)

            // Images: self + data: URIs (Filament inline SVG + laravolt avatar)
            ->add(Directive::IMG, [Keyword::SELF, 'data:'])

            // Fonts: self only (Inter served from /fonts/inter/)
            ->add(Directive::FONT, [Keyword::SELF, 'data:'])

            // Styles: self + unsafe-inline (Tailwind utility classes + Filament inline)
            ->add(Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE])
            ->add(Directive::STYLE_ELEM, [Keyword::SELF, Keyword::UNSAFE_INLINE])
            ->add(Directive::STYLE_ATTR, [Keyword::SELF, Keyword::UNSAFE_INLINE])

            // Scripts: self + unsafe-inline + unsafe-eval (Alpine.js eval)
            ->add(Directive::SCRIPT, [Keyword::SELF, Keyword::UNSAFE_INLINE, Keyword::UNSAFE_EVAL])
            ->add(Directive::SCRIPT_ELEM, [Keyword::SELF, Keyword::UNSAFE_INLINE])
            ->add(Directive::SCRIPT_ATTR, [Keyword::SELF, Keyword::UNSAFE_INLINE])

            // Connections: self + wss: (Livewire WebSocket if Reverb enabled)
            ->add(Directive::CONNECT, [Keyword::SELF, 'wss:']);
    }
}
