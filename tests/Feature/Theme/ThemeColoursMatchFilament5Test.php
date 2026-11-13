<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\BrandingPage;
use App\Models\User;
use App\Settings\BrandingSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * 2026-11-12, found while rewriting the manual:
 *  - the cream reset on every .fi-badge also flattened the badges the code
 *    colours (Audit log Event, RAS reconciliation Result);
 *  - the primary-button rules targeted [data-color="primary"], which Filament 5
 *    no longer prints, so "Save changes" was a light teal with dark text;
 *  - the Branding page's logo preview was stretched sideways.
 *
 * The CSS is checked against the class names Filament 5 really renders.
 */
uses(RefreshDatabase::class);

/**
 * The rules of the admin theme as [selectors[], declarations] pairs, comments
 * removed. Only innermost blocks are kept, so rules inside @media count too.
 *
 * @return list<array{0: list<string>, 1: string}>
 */
function themeRules(): array
{
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);

    return array_map(static fn (array $rule): array => [
        array_map(static fn (string $s): string => (string) preg_replace('/\s+/', ' ', trim($s)), explode(',', $rule[1])),
        $rule[2],
    ], $m);
}

/**
 * The declarations of every rule whose selector list contains $selector.
 */
function themeDeclarationsFor(string $selector): string
{
    return collect(themeRules())
        ->filter(fn (array $rule): bool => in_array($selector, $rule[0], true))
        ->map(fn (array $rule): string => $rule[1])
        ->implode("\n");
}

/**
 * The class attribute of the first element in $html carrying $marker.
 */
function renderedClasses(string $html, string $marker): string
{
    preg_match('/class="([^"]*\b' . preg_quote($marker, '/') . '\b[^"]*)"/', $html, $m);

    return $m[1] ?? '';
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('Filament 5 marks coloured badges and primary buttons with fi-color-* classes', function (): void {
    expect(renderedClasses(Blade::render('<x-filament::badge color="warning">updated</x-filament::badge>'), 'fi-badge'))
        ->toContain('fi-color-warning');
    expect(renderedClasses(Blade::render('<x-filament::badge color="danger">deleted</x-filament::badge>'), 'fi-badge'))
        ->toContain('fi-color-danger');
    // A gray badge carries no colour class: it is the neutral one.
    expect(renderedClasses(Blade::render('<x-filament::badge color="gray">x</x-filament::badge>'), 'fi-badge'))
        ->not->toContain('fi-color');
    expect(renderedClasses(Blade::render('<x-filament::button color="primary">Save changes</x-filament::button>'), 'fi-btn'))
        ->toContain('fi-color-primary')
        ->not->toContain('fi-outlined');
});

it('keeps the cream reset off the badges the code colours', function (): void {
    // No rule may paint every badge cream any more ...
    foreach (themeRules() as [$selectors, $declarations]) {
        foreach ($selectors as $selector) {
            if (in_array($selector, ['.fi-badge', '.dark .fi-badge'], true)) {
                expect($declarations)->not->toContain('background-color')
                    ->and($declarations)->not->toMatch('/(^|[;\s])color:/');
            }
        }
    }

    // ... the neutral ones stay cream ...
    expect(themeDeclarationsFor('.fi-badge:not(.fi-color)'))->toContain('var(--cream-200)');

    // ... and success / warning / danger have their own colour, in both modes.
    foreach (['success', 'warning', 'danger'] as $colour) {
        foreach (['.fi-badge.fi-color-' . $colour, '.dark .fi-badge.fi-color-' . $colour] as $selector) {
            $declarations = themeDeclarationsFor($selector);
            expect($declarations)->toContain('background-color: oklch(')
                ->and($declarations)->not->toContain('cream');
        }
    }
});

it('paints primary buttons in the panel primary with a white label', function (): void {
    $declarations = themeDeclarationsFor('.fi-btn.fi-color-primary:not(.fi-outlined)');

    expect($declarations)->toContain('background-color: var(--brand-500)')
        ->and($declarations)->toContain('color: #fff');

    // The Filament 3 selectors that matched nothing are gone.
    $all = collect(themeRules())->flatMap(fn (array $rule): array => $rule[0]);
    expect($all->filter(fn (string $s): bool => str_contains($s, 'fi-btn[data-color="primary"]') || str_contains($s, 'fi-btn-color-primary')))
        ->toBeEmpty();
});

it('keeps the proportions of the logo in the Branding preview', function (): void {
    bl_seedShieldPermissions();
    Storage::fake('public');
    Storage::disk('public')->put('branding/wide-logo.png', 'fake-image-content');

    $settings = app(BrandingSettings::class);
    $settings->logo_path = 'branding/wide-logo.png';
    $settings->save();

    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(BrandingPage::class)
        ->assertSeeHtml('class="nra-branding-logo-preview"');

    $declarations = themeDeclarationsFor('.nra-branding-logo-preview');
    expect($declarations)->toContain('width: auto')
        ->and($declarations)->toContain('height: auto')
        ->and($declarations)->toContain('object-fit: contain');
});
