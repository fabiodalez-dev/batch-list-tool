<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentItem>
 */
class DocumentItemFactory extends Factory
{
    protected $model = DocumentItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'position' => 1,
            'reference' => 'Folder ' . $this->faker->unique()->numberBetween(1, 100000),
            'description' => null,
        ];
    }
}
