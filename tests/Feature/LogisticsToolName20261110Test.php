<?php

declare(strict_types=1);

use App\Filament\Pages\UserGuide;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Client 2026-11-10: "can we also rename the whole tool Logistics tool".
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->actingAs(bl_actor('super_admin', Repository::factory()->create()));
});

it('names the tool Logistics Tool in the page titles', function (): void {
    auth()->logout();

    $html = $this->get('/admin/login')->assertOk()->getContent();
    preg_match('#<title>(.*?)</title>#s', (string) $html, $m);

    expect(preg_replace('/\s+/', ' ', trim($m[1] ?? '')))->toBe('Login - Logistics Tool');
});

it('renames a stored NAf brand name but keeps one an administrator chose', function (): void {
    $migration = require database_path('settings/2026_11_10_150000_rename_the_tool_logistics_tool.php');
    $set = fn (string $v) => DB::table('settings')->where('group', 'branding')->where('name', 'brand_name')->update(['payload' => json_encode($v)]);
    $get = fn (): string => json_decode((string) DB::table('settings')->where('group', 'branding')->where('name', 'brand_name')->value('payload'));

    $set('NAf');
    $migration->up();
    expect($get())->toBe('Logistics Tool');

    $set('NRA Archive');
    $migration->up();
    expect($get())->toBe('NRA Archive');
});

it('titles the user guide Logistics Tool', function (): void {
    expect(json_decode((string) file_get_contents(resource_path('manual/manifest.json')), true)['title'])->toBe('Logistics Tool');
    Livewire\Livewire::test(UserGuide::class)->assertOk()->assertSee('Logistics Tool')->assertDontSee('Archive Management Tool');
});
