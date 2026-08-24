<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Enums\AvailabilityKind;
use Carbon\CarbonInterface;
use Database\Factories\ResourceAvailabilityRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property AvailabilityKind $kind
 * @property CarbonInterface|null $effective_from
 * @property CarbonInterface|null $effective_until
 */
#[Fillable(['organization_id', 'scheduling_resource_id', 'academic_period_id', 'kind', 'weekday', 'starts_at_minute', 'ends_at_minute', 'effective_from', 'effective_until', 'priority'])]
class ResourceAvailabilityRule extends Model
{
    /** @use HasFactory<ResourceAvailabilityRuleFactory> */
    use HasFactory, HasImmutableOrganization;

    protected static function booted(): void
    {
        static::saving(function (ResourceAvailabilityRule $rule): void {
            if ($rule->weekday < 1 || $rule->weekday > 7
                || $rule->ends_at_minute > 1440
                || $rule->starts_at_minute >= $rule->ends_at_minute) {
                throw new LogicException('Availability rules must use a positive weekday time window.');
            }

            if ($rule->effective_from && $rule->effective_until && $rule->effective_from->greaterThan($rule->effective_until)) {
                throw new LogicException('Availability rules must end on or after their effective start date.');
            }

            $resourceOrganizationId = SchedulingResource::query()->whereKey($rule->scheduling_resource_id)->value('organization_id');

            if ($resourceOrganizationId !== null && $resourceOrganizationId !== $rule->organization_id) {
                throw new LogicException('An availability rule and its resource must belong to the same organization.');
            }

            $periodOrganizationId = $rule->academic_period_id === null
                ? null
                : AcademicPeriod::query()->whereKey($rule->academic_period_id)->value('organization_id');

            if ($periodOrganizationId !== null && $periodOrganizationId !== $rule->organization_id) {
                throw new LogicException('An availability rule and its period must belong to the same organization.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<SchedulingResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class, 'scheduling_resource_id');
    }

    /** @return BelongsTo<AcademicPeriod, $this> */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => AvailabilityKind::class,
            'weekday' => 'integer',
            'starts_at_minute' => 'integer',
            'ends_at_minute' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'priority' => 'integer',
        ];
    }
}
