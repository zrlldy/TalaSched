<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => fake()->unique()->bothify('SUB-####'),
            'name' => fake()->randomElement(['Mathematics', 'English', 'Computer Programming', 'Physics', 'Science']),
            'description' => fake()->sentence(),
            'units' => fake()->randomElement([1, 2, 3, 4]),
            'is_active' => true,
        ];
    }
}
