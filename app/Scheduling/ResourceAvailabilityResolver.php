<?php

namespace App\Scheduling;

use App\Enums\AvailabilityKind;
use App\Enums\CalendarExceptionKind;
use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\CalendarException;
use App\Models\Organization;
use App\Models\ResourceAvailabilityRule;
use App\Models\SchedulingResource;
use App\Models\TimetableVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ResourceAvailabilityResolver
{
    /**
     * Resolve hard availability conflicts and soft preference warnings for recurring time.
     *
     * @param  Collection<int, SchedulingResource>  $resources
     * @return array<int, array<string, mixed>>
     */
    public function issues(
        Organization $organization,
        TimetableVersion $version,
        Collection $resources,
        int $weekday,
        int $startsAtMinute,
        int $endsAtMinute,
        ?CarbonInterface $occurrenceDate = null,
        ?CalendarException $calendarException = null,
    ): array {
        $period = AcademicPeriod::query()
            ->whereKey($version->timetable->academic_period_id)
            ->where('organization_id', $organization->getKey())
            ->firstOrFail();
        $calendar = AcademicCalendar::query()
            ->where('organization_id', $organization->getKey())
            ->where('academic_period_id', $period->getKey())
            ->where('weekday', $weekday)
            ->first();
        $rules = ResourceAvailabilityRule::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('scheduling_resource_id', $resources->pluck('id'))
            ->where('weekday', $weekday)
            ->where(function ($query) use ($period): void {
                $query->whereNull('academic_period_id')
                    ->orWhere('academic_period_id', $period->getKey());
            })
            ->where(function ($query) use ($period): void {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $period->ends_on);
            })
            ->where(function ($query) use ($period): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $period->starts_on);
            })
            ->orderBy('priority')
            ->get()
            ->groupBy('scheduling_resource_id');

        $issues = [];

        foreach ($resources as $resource) {
            /** @var Collection<int, ResourceAvailabilityRule> $resourceRules */
            $resourceRules = $rules->get($resource->getKey(), new Collection);
            $allAvailableRules = $resourceRules->where('kind', AvailabilityKind::Available);

            if ($occurrenceDate !== null) {
                $resourceRules = $resourceRules->filter(
                    fn (ResourceAvailabilityRule $rule): bool => $this->appliesOnDate($rule, $occurrenceDate),
                );
            }

            $periodRules = $resourceRules->where('academic_period_id', $period->getKey());
            $globalRules = $resourceRules->whereNull('academic_period_id');
            $availableRules = $this->preferSpecificScope($periodRules, $globalRules, AvailabilityKind::Available);
            $preferredRules = $this->preferSpecificScope($periodRules, $globalRules, AvailabilityKind::Preferred);
            $avoidRules = $this->preferSpecificScope($periodRules, $globalRules, AvailabilityKind::Avoid);
            $unavailableRules = $resourceRules->where('kind', AvailabilityKind::Unavailable);
            $calendarWindow = $this->calendarWindow($calendar, $calendarException);

            if ($occurrenceDate === null) {
                foreach ($resourceRules->whereIn('kind', [AvailabilityKind::Available, AvailabilityKind::Unavailable]) as $rule) {
                    if ($this->coversPeriod($rule, $period)) {
                        continue;
                    }

                    $issues[] = $this->issue(
                        $resource,
                        'resource_effective_date_range',
                        'hard',
                        'The recurring schedule requires a resource rule to cover the full academic period.',
                        [
                            'source' => 'resource_availability_rule',
                            'rule_id' => $rule->getKey(),
                            'effective_from' => $rule->effective_from?->toDateString(),
                            'effective_until' => $rule->effective_until?->toDateString(),
                        ],
                    );
                }
            }

            if ($calendarWindow !== null && ! $this->containsWindow($calendarWindow[0], $calendarWindow[1], $startsAtMinute, $endsAtMinute)) {
                $issues[] = $this->issue(
                    $resource,
                    'resource_unavailable',
                    'hard',
                    'The resource is outside the academic period operating hours.',
                    ['source' => $calendarException === null ? 'academic_calendar' : 'calendar_exception'],
                );
            }

            if ($availableRules->isNotEmpty()
                && ! $availableRules->contains(fn (ResourceAvailabilityRule $rule): bool => $this->containsWindow($rule->starts_at_minute, $rule->ends_at_minute, $startsAtMinute, $endsAtMinute))) {
                $issues[] = $this->issue(
                    $resource,
                    'resource_unavailable',
                    'hard',
                    'The resource is outside its hard available windows.',
                    ['source' => 'resource_available_rule'],
                );
            }

            if ($occurrenceDate !== null && $allAvailableRules->isNotEmpty() && $availableRules->isEmpty()) {
                $issues[] = $this->issue(
                    $resource,
                    'resource_unavailable',
                    'hard',
                    'The resource has no hard available window on the selected date.',
                    ['source' => 'resource_available_rule', 'date' => $occurrenceDate->toDateString()],
                );
            }

            foreach ($unavailableRules as $rule) {
                if ($this->overlaps($rule->starts_at_minute, $rule->ends_at_minute, $startsAtMinute, $endsAtMinute)) {
                    $issues[] = $this->issue(
                        $resource,
                        'resource_unavailable',
                        'hard',
                        'The resource is unavailable during this time.',
                        ['source' => 'resource_unavailable_rule', 'rule_id' => $rule->getKey()],
                    );
                }
            }

            if ($preferredRules->isNotEmpty()
                && ! $preferredRules->contains(fn (ResourceAvailabilityRule $rule): bool => $this->containsWindow($rule->starts_at_minute, $rule->ends_at_minute, $startsAtMinute, $endsAtMinute))) {
                $issues[] = $this->issue(
                    $resource,
                    'resource_preference',
                    'soft',
                    'The scheduled time is outside the resource preferred windows.',
                    ['source' => 'resource_preferred_rule'],
                );
            }

            foreach ($avoidRules as $rule) {
                if ($this->overlaps($rule->starts_at_minute, $rule->ends_at_minute, $startsAtMinute, $endsAtMinute)) {
                    $issues[] = $this->issue(
                        $resource,
                        'resource_preference',
                        'soft',
                        'The scheduled time overlaps a resource avoid window.',
                        ['source' => 'resource_avoid_rule', 'rule_id' => $rule->getKey()],
                    );
                }
            }
        }

        return $issues;
    }

    /** @return array{int, int}|null */
    private function calendarWindow(?AcademicCalendar $calendar, ?CalendarException $calendarException): ?array
    {
        if ($calendarException?->kind === CalendarExceptionKind::Teaching) {
            if ($calendarException->starts_at_minute === null || $calendarException->ends_at_minute === null) {
                return null;
            }

            return [$calendarException->starts_at_minute, $calendarException->ends_at_minute];
        }

        if ($calendar === null) {
            return null;
        }

        return [$calendar->starts_at_minute, $calendar->ends_at_minute];
    }

    private function coversPeriod(ResourceAvailabilityRule $rule, AcademicPeriod $period): bool
    {
        return ($rule->effective_from === null || $rule->effective_from->lte($period->starts_on))
            && ($rule->effective_until === null || $rule->effective_until->gte($period->ends_on));
    }

    private function appliesOnDate(ResourceAvailabilityRule $rule, CarbonInterface $date): bool
    {
        return ($rule->effective_from === null || $rule->effective_from->lessThanOrEqualTo($date))
            && ($rule->effective_until === null || $rule->effective_until->greaterThanOrEqualTo($date));
    }

    /**
     * A period-specific rule replaces organization-wide rules of the same kind.
     * Unavailable rules remain additive because they are hard blocks.
     *
     * @param  Collection<int, ResourceAvailabilityRule>  $periodRules
     * @param  Collection<int, ResourceAvailabilityRule>  $globalRules
     * @return Collection<int, ResourceAvailabilityRule>
     */
    private function preferSpecificScope(
        Collection $periodRules,
        Collection $globalRules,
        AvailabilityKind $kind,
    ): Collection {
        $specific = $periodRules->where('kind', $kind);

        return $specific->isNotEmpty() ? $specific : $globalRules->where('kind', $kind);
    }

    private function containsWindow(int $windowStart, int $windowEnd, int $startsAtMinute, int $endsAtMinute): bool
    {
        return $windowStart <= $startsAtMinute && $windowEnd >= $endsAtMinute;
    }

    private function overlaps(int $windowStart, int $windowEnd, int $startsAtMinute, int $endsAtMinute): bool
    {
        return $windowStart < $endsAtMinute && $windowEnd > $startsAtMinute;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function issue(
        SchedulingResource $resource,
        string $code,
        string $severity,
        string $message,
        array $details,
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'field' => 'resources',
            'rule_code' => $code,
            'message' => $message,
            'details' => $details,
            'resource' => [
                'id' => $resource->public_id,
                'name' => $resource->name,
            ],
            'conflicting_entry_id' => null,
        ];
    }
}
