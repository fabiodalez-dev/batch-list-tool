<?php

namespace Database\Factories;

use App\Models\Box;
use App\Models\BoxBarcodeHistory;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoxBarcodeHistory>
 */
class BoxBarcodeHistoryFactory extends Factory
{
    protected $model = BoxBarcodeHistory::class;

    public function definition(): array
    {
        $statuses = ['IN', 'OUT', 'PERM_OUT'];

        return [
            'box_id' => Box::factory(),
            'previous_barcode' => 'BC' . fake()->unique()->numerify('########'),
            'new_barcode' => 'BC' . fake()->unique()->numerify('########'),
            'previous_status' => fake()->randomElement($statuses),
            'new_status' => fake()->randomElement($statuses),
            'changed_at' => fake()->dateTimeBetween('-2 years', 'now'),
            'changed_by_user_id' => User::factory(),
            'reason' => fake()->optional()->sentence(6),
            'repository_id' => Repository::factory(),
        ];
    }

    /**
     * No operator note attached.
     */
    public function withoutReason(): static
    {
        return $this->state(fn () => ['reason' => null]);
    }

    /**
     * Back-fill: an explicit historical timestamp.
     */
    public function backDatedTo(string|\DateTimeInterface $when): static
    {
        return $this->state(fn () => ['changed_at' => $when]);
    }

    /**
     * Anonymous transition (no operator captured) — e.g. legacy import.
     */
    public function anonymous(): static
    {
        return $this->state(fn () => ['changed_by_user_id' => null]);
    }
}
