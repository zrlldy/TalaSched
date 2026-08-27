<?php

namespace Database\Factories;

use App\Models\SignatoryProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignatoryProfile>
 */
class SignatoryProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->withOwnedOrganization(),
            'organization_id' => fn (array $attributes): int => (int) User::query()
                ->whereKey((int) $attributes['user_id'])
                ->value('current_organization_id'),
            'academic_unit_id' => null,
            'name' => fake()->name(),
            'position' => fake()->jobTitle(),
            'academic_unit_name' => null,
            'signature_disk' => null,
            'signature_path' => null,
            'signature_checksum' => null,
            'valid_from' => null,
            'valid_until' => null,
        ];
    }
}
