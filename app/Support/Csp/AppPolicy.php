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
 * Filament 5 + Livewire 4 + Alpine.js emit inline `<script>` + inline `<style>`
 * during render (verified against /admin/login: livewire.min.js bootstrap +
 * inline `<script>` for Livewire init + inline `<style>` for theme overrides),
 * so `'unsafe-inline'` on script/style is a HARD requirement until nonce-
 * propagation is wired end-to-end. `'unsafe-eval'` is required by Alpine.js
 * for `x-data` expression compilation; switching Alpine to the CSP-build
 * (csp.js) would let us drop it but requires a Filament-side change too.
 *
 * Hardening applied 2026-05-28 (OWASP A05 follow-up):
 *   - Added missing directives (FRAME, WORKER, MEDIA, MANIFEST) to prevent
 *     fallback to `default-src` defaults.
 *   - Explicit `upgrade-insecure-requests` to auto-rewrite http:// to
 *     https:// in any user-supplied content.
 *   - {@see StrictReportOnlyPolicy} runs in parallel with this enforced
 *     policy to surface real-world violations the future nonce upgrade
 *     would catch — Spatie Csp plugin reports both headers per response.
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

            // Block embedded content we never legitimately serve.
            ->add(Directive::FRAME, Keyword::NONE)

            // Worker scripts (Pulse uses none, but keep ready for future use).
            ->add(Directive::WORKER, Keyword::SELF)

            // <video> / <audio> / <track> — none in scope, lock to self.
            ->add(Directive::MEDIA, Keyword::SELF)

            // PWA manifest — currently absent, but locked anyway.
            ->add(Directive::MANIFEST, Keyword::SELF)

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
            ->add(Directive::CONNECT, [Keyword::SELF, 'wss:'])

            // Auto-rewrite any http:// resource to https:// — defends against
            // a misconfigured iframe / OEmbed leaking the page over plain HTTP.
            ->add(Directive::UPGRADE_INSECURE_REQUESTS, []);
    }
}
