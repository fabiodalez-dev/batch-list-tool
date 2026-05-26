<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Location>
 *
 * Defaults to a root "repository" type — handy for tests that just need
 * "any location, no parent". Use ->ofType('shelf')->forParent($x) (state
 * methods below) for nested nodes.
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => 'Loc ' . Str::upper(Str::random(4)),
            'code' => null,
            'type' => 'repository',
            'repository_id' => null, // global by default — tests opt-in
            'is_active' => true,
            'sort_order' => null,
            'notes' => null,
        ];
    }

    public function ofType(string $type): self
    {
        return $this->state(['type' => $type]);
    }

    public function child(Location $parent, string $type = 'room'): self
    {
        return $this->state([
            'parent_id' => $parent->id,
            'type' => $type,
            'repository_id' => $parent->repository_id,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
