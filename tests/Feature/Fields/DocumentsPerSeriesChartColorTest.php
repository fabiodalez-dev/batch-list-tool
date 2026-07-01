<?php

declare(strict_types=1);

use App\Filament\Widgets\DocumentsPerSeriesChart;
use App\Models\Document;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Regression — the "Documents by Series" doughnut widget rendered invisible on
 * production because the dataset carried no per-segment colours, so Filament
 * filled every slice with the near-white primary. getData() must now supply a
 * distinct, non-white colour per slice.
 */
uses(RefreshDatabase::class);

function chartData(DocumentsPerSeriesChart $w): array
{
    $m = new ReflectionMethod($w, 'getData');
    $m->setAccessible(true);

    return $m->invoke($w);
}

it('assigns one distinct, saturated colour per doughnut slice', function (): void {
    $m = new ReflectionMethod(DocumentsPerSeriesChart::class, 'segmentColors');
    $m->setAccessible(true);

    $colors = $m->invoke(null, 5);

    expect($colors)->toHaveCount(5)
        ->and($colors[0])->toBe('#4A6F77')                    // starts on the brand green
        ->and(array_unique($colors))->toHaveCount(5)          // all distinct
        ->and($colors)->each->toMatch('/^#[0-9A-Fa-f]{6}$/'); // valid hex
});

it('cycles the palette when there are more slices than colours', function (): void {
    $m = new ReflectionMethod(DocumentsPerSeriesChart::class, 'segmentColors');
    $m->setAccessible(true);

    $colors = $m->invoke(null, 25);

    expect($colors)->toHaveCount(25)
        ->and($colors[20])->toBe($colors[0]); // 20-colour palette wraps
});

it('never emits a near-white slice colour', function (): void {
    $m = new ReflectionMethod(DocumentsPerSeriesChart::class, 'segmentColors');
    $m->setAccessible(true);

    foreach ($m->invoke(null, 20) as $hex) {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        // Reject anything close to white (the original invisible #EFF3F4).
        expect(min($r, $g, $b))->toBeLessThan(200, "colour {$hex} is too light");
    }
});

it('includes a backgroundColor array matching the data length', function (): void {
    $repo = qf_repo();
    $this->actingAs(qf_admin($repo->id));

    $regId = Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true])->id;
    $idxId = Series::firstOrCreate(['code' => 'IDX'], ['title' => 'Index', 'is_active' => true])->id;
    Document::factory()->count(3)->create(['series_id' => $regId, 'repository_id' => $repo->id]);
    Document::factory()->create(['series_id' => $idxId, 'repository_id' => $repo->id]);

    $data = chartData(new DocumentsPerSeriesChart);
    $dataset = $data['datasets'][0];

    expect($dataset['backgroundColor'])->toBeArray()
        ->and($dataset['backgroundColor'])->toHaveCount(count($dataset['data']))
        ->and($dataset['backgroundColor'])->not->toContain('#EFF3F4');
});
