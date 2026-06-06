<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'identifier' => 'R' . fake()->unique()->numberBetween(1, 99999),
            'document_type' => 'Register',
            'series_id' => Series::factory(),
            'repository_id' => Repository::factory(),
            'volume_number' => null,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
