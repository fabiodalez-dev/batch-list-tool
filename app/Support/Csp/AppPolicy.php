<?php

namespace App\Support\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policies\Policy;

/**
 * Content Security Policy aligned with the Development Security Baseline §6.
 *
 * Filament + Livewire need 'unsafe-inline' + 'unsafe-eval' on scripts;
 * Tailwind generated styles need 'unsafe-inline' on style-src. These
 * relaxations are documented as accepted in baseline §15 (exclusions).
 */
class AppPolicy extends Policy
{
    public function configure(): void
    {
        $this
            ->addDirective(Directive::BASE, Keyword::SELF)
            ->addDirective(Directive::DEFAULT, Keyword::SELF)
            ->addDirective(Directive::FORM_ACTION, Keyword::SELF)
            ->addDirective(Directive::FRAME_ANCESTORS, Keyword::NONE)
            ->addDirective(Directive::IMG, [Keyword::SELF, 'data:', 'https:'])
            ->addDirective(Directive::CONNECT, [Keyword::SELF, 'wss:'])
            ->addDirective(Directive::FONT, [Keyword::SELF, 'data:', 'https:'])
            ->addDirective(Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE])
            ->addDirective(Directive::SCRIPT, [Keyword::SELF, Keyword::UNSAFE_INLINE, Keyword::UNSAFE_EVAL])
            ->addDirective(Directive::OBJECT, Keyword::NONE);
    }
}
