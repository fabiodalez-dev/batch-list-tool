<?php

declare(strict_types=1);

namespace App\Support\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;

/**
 * Strict CSP run in `Content-Security-Policy-Report-Only` mode alongside
 * {@see AppPolicy}.
 *
 * Why this exists: {@see AppPolicy} relaxes script/style to
 * `'unsafe-inline'` + `'unsafe-eval'` because Filament 5 + Livewire 4 +
 * Alpine.js emit inline `<script>` / `<style>` + use `eval` for `x-data`.
 * Tightening that requires propagating Spatie's nonce through every
 * Filament render — non-trivial, scheduled for post-M5.
 *
 * In the meantime, this report-only policy mirrors what the strict
 * eventual policy will look like, so the browser exercises every page
 * against the strict rules without breaking anything. Violations land
 * in `report-uri` (currently not wired — see TODO below) for later
 * triage. The header co-exists with the enforced one.
 *
 * Toggle: opt-in via `CSP_STRICT_REPORT_ONLY=true` env. Default off so
 * staging / dev don't get console noise during the upgrade. Production
 * can opt in once an endpoint is wired up.
 */
class StrictReportOnlyPolicy implements Preset
{
    public function configure(Policy $policy): void
    {
        $policy
            ->add(Directive::BASE, Keyword::SELF)
            ->add(Directive::DEFAULT, Keyword::NONE)
            ->add(Directive::FORM_ACTION, Keyword::SELF)
            ->add(Directive::FRAME_ANCESTORS, Keyword::NONE)
            ->add(Directive::OBJECT, Keyword::NONE)
            ->add(Directive::FRAME, Keyword::NONE)
            ->add(Directive::WORKER, Keyword::SELF)
            ->add(Directive::MEDIA, Keyword::SELF)
            ->add(Directive::MANIFEST, Keyword::SELF)

            ->add(Directive::IMG, [Keyword::SELF, 'data:'])
            ->add(Directive::FONT, [Keyword::SELF, 'data:'])

            // STRICT: only self + nonce — no unsafe-inline / unsafe-eval.
            // When Spatie's nonce is enabled (CSP_NONCE_ENABLED=true) Spatie
            // injects `'nonce-XYZ'` automatically into the directive string.
            ->add(Directive::STYLE, Keyword::SELF)
            ->add(Directive::STYLE_ELEM, Keyword::SELF)
            ->add(Directive::SCRIPT, [Keyword::SELF, Keyword::STRICT_DYNAMIC])
            ->add(Directive::SCRIPT_ELEM, [Keyword::SELF, Keyword::STRICT_DYNAMIC])

            ->add(Directive::CONNECT, [Keyword::SELF, 'wss:'])
            ->add(Directive::UPGRADE_INSECURE_REQUESTS, []);

        // TODO: wire `report-uri /csp-violations` once an endpoint exists
        // — Spatie supports it via `->add(Directive::REPORT_URI, '/csp-violations')`.
    }
}
