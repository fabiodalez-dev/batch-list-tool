<?php

declare(strict_types=1);

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\TwoFactorProfile;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Support\PagePurposes;

it('registry keys are all real page classes', function () {
    foreach (array_keys(PagePurposes::all()) as $class) {
        expect(class_exists($class))->toBeTrue("Missing class: {$class}");
    }
});

it('every registry entry has a non-empty body and refs', function () {
    foreach (PagePurposes::all() as $class => $e) {
        expect(trim($e['body']))->not->toBe('', "Empty body for {$class}");
        expect(trim($e['refs'] ?? ''))->not->toBe('', "Empty refs for {$class}");
    }
});

it('every page with a registry entry uses the ExplainsPage trait (except documented skips)', function () {
    // TwoFactorProfile defines its own getSubheading(), so it is intentionally
    // NOT given the trait (its entry stays for documentation but does not render).
    $allowedWithoutTrait = [TwoFactorProfile::class];

    foreach (array_keys(PagePurposes::all()) as $class) {
        if (in_array($class, $allowedWithoutTrait, true)) {
            continue;
        }
        $usesTrait = in_array(ExplainsPage::class, class_uses_recursive($class), true);
        expect($usesTrait)->toBeTrue("Page {$class} has a registry entry but does not use ExplainsPage");
    }
});

it('the Boxes page no longer says a box needs a disinfestation date to go PERM OUT', function () {
    // The rule was dropped on the client's 2026-08-01 feedback (Box model,
    // BoxImporter); only the Documents "Mark PERM_OUT" action still asks.
    $body = PagePurposes::for(ListBoxes::class)['body'];

    expect($body)->not->toContain('cannot be set PERM OUT without a disinfestation date')
        ->and($body)->toContain('a box can be set PERM OUT without a disinfestation date');
});
