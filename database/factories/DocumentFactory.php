<?php

namespace Database\Factories;

use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'identifier' => 'R' . $this->faker->unique()->numberBetween(1, 99999),
            'document_type' => 'register',
            'series_id' => Series::factory(),
            'repository_id' => Repository::factory(),
            'current_box_id' => null,   // tests that need a box opt-in
            'batch_id' => null,
            'volume_label' => null,
        ];
    }

    public function inBox(?Box $box = null): static
    {
        return $this->state(fn () => ['current_box_id' => ($box ?? Box::factory())->id]);
    }
}
