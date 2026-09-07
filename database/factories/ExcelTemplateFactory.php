<?php

namespace Database\Factories;

use App\Enums\ExcelTemplateStatus;
use App\Models\ExcelTemplate;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExcelTemplate>
 */
class ExcelTemplateFactory extends Factory
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
            'name' => fake()->unique()->sentence(3),
            'status' => ExcelTemplateStatus::Draft,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->getKey(),
        ]);
    }
}
