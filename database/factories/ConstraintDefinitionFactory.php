<?php

namespace Database\Factories;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConstraintDefinition> */
class ConstraintDefinitionFactory extends Factory
{
    protected $model = ConstraintDefinition::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(3, true),
            'handler' => 'App\\Scheduling\\Constraints\\TestConstraintHandler',
            'default_severity' => ConstraintSeverity::Hard->value,
            'configuration_schema_version' => 1,
            'is_mandatory' => false,
        ];
    }
}
