<?php

namespace Database\Factories;

use App\Enums\AvailabilityKind;
use App\Models\AcademicPeriod;
use App\Models\ResourceAvailabilityRule;
use App\Models\SchedulingResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ResourceAvailabilityRule> */
class ResourceAvailabilityRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scheduling_resource_id' => SchedulingResource::factory(),
            'organization_id' => fn (array $attributes): int => SchedulingResource::query()->findOrFail((int) $attributes['scheduling_resource_id'])->organization_id,
            'academic_period_id' => null,
            'kind' => AvailabilityKind::Available,
            'weekday' => 1,
            'starts_at_minute' => 480,
            'ends_at_minute' => 1020,
            'effective_from' => null,
            'effective_until' => null,
            'priority' => 0,
        ];
    }

    public function forResource(SchedulingResource $resource): static
    {
        return $this->state([
            'scheduling_resource_id' => $resource->getKey(),
            'organization_id' => $resource->organization_id,
        ]);
    }

    public function forPeriod(AcademicPeriod $academicPeriod): static
    {
        return $this->state([
            'academic_period_id' => $academicPeriod->getKey(),
            'organization_id' => $academicPeriod->organization_id,
        ]);
    }
}
