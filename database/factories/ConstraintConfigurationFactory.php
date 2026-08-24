<?php

namespace Database\Factories;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Models\ConstraintDefinition;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConstraintConfiguration> */
class ConstraintConfigurationFactory extends Factory
{
    protected $model = ConstraintConfiguration::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'constraint_definition_id' => ConstraintDefinition::factory(),
            'academic_unit_id' => null,
            'academic_period_id' => null,
            'is_enabled' => true,
            'severity' => ConstraintSeverity::Hard,
            'priority' => 0,
            'weight' => 1,
            'schema_version' => 1,
            'configuration' => [],
        ];
    }
}
