<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Box> */
class BoxFactory extends Factory
{
    protected $model = Box::class;

    public function definition(): array
    {
        return [
            'box_type' => 'RAS',
            'box_number' => $this->faker->unique()->numerify('B###'),
            'batch_id' => Batch::factory(),
            'barcode' => 'BC' . $this->faker->unique()->numerify('########'),
            'barcode_status' => 'IN',
            'is_legacy' => false,
        ];
    }

    /**
     * F5 (review finding) — IN_SITU / NRA boxes require a Location at the model
     * level (RFQ Feedback1 C2.1). Tests routinely override `box_type` to
     * IN_SITU/NRA without supplying a Location; backfill one here so the
     * factory always produces a structurally-valid box unless a test
     * explicitly sets `location_id`.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Box $box): void {
            if (in_array($box->box_type, ['IN_SITU', 'NRA'], true) && $box->location_id === null) {
                $box->location_id = Location::factory()->create()->id;
            }
        });
    }
}
