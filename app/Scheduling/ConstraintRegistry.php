<?php

namespace App\Scheduling;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use Illuminate\Support\Collection;

class ConstraintRegistry
{
    /** @param iterable<ConstraintHandler> $handlers */
    public function __construct(private iterable $handlers) {}

    /**
     * Return the canonical definitions used by the scheduling registry.
     *
     * @return list<array{code: string, name: string, handler: class-string<ConstraintHandler>, default_severity: string, configuration_schema_version: int, is_mandatory: bool}>
     */
    public static function definitions(): array
    {
        return [
            self::definition('version_not_editable', 'Editable timetable version', Constraints\VersionEditableConstraintHandler::class, ConstraintSeverity::Hard, true),
            self::definition('invalid_granularity', 'Scheduling granularity', Constraints\GranularityConstraintHandler::class, ConstraintSeverity::Hard, true),
            self::definition('calendar_operating_hours', 'Academic calendar operating hours', Constraints\AcademicCalendarConstraintHandler::class, ConstraintSeverity::Hard, true),
            self::definition('resource_role_mismatch', 'Resource role compatibility', Constraints\ResourceRoleConstraintHandler::class, ConstraintSeverity::Hard, true),
            self::definition('resource_inactive', 'Active scheduling resources', Constraints\ResourceActiveConstraintHandler::class, ConstraintSeverity::Hard, true),
            self::definition('instructor_eligibility', 'Offering instructor eligibility', Constraints\InstructorEligibilityConstraintHandler::class, ConstraintSeverity::Hard, true),
            self::definition('offering_group_mismatch', 'Offering group integrity', Constraints\OfferingGroupConstraintHandler::class, ConstraintSeverity::Hard, true),
            self::definition('resource_overlap', 'Resource overlap', Constraints\ResourceOverlapConstraintHandler::class, ConstraintSeverity::Hard, true),
            self::definition('resource_unavailable', 'Resource availability', Constraints\ResourceAvailabilityConstraintHandler::class, ConstraintSeverity::Hard, true),
            self::definition('room_capacity', 'Room capacity', Constraints\RoomCapacityConstraintHandler::class, ConstraintSeverity::Hard, false),
            self::definition('room_type_required', 'Room type requirement', Constraints\RoomTypeConstraintHandler::class, ConstraintSeverity::Hard, false),
            self::definition('room_features_required', 'Room feature requirements', Constraints\RoomFeatureConstraintHandler::class, ConstraintSeverity::Hard, false),
            self::definition('faculty_load', 'Faculty daily and weekly load', Constraints\FacultyLoadConstraintHandler::class, ConstraintSeverity::Hard, false),
            self::definition('offering_fulfillment', 'Offering session and hour fulfillment', Constraints\OfferingFulfillmentConstraintHandler::class, ConstraintSeverity::Hard, true),
            self::definition('organization_blocked_time', 'Organization-wide blocked times', Constraints\OrganizationBlockedTimeConstraintHandler::class, ConstraintSeverity::Hard, true),
        ];
    }

    public function evaluate(SchedulingContext $context): ConstraintResult
    {
        $configurations = ConstraintConfiguration::query()
            ->with('definition')
            ->where('organization_id', $context->organization->getKey())
            ->where(function ($query) use ($context): void {
                $query->whereNull('academic_period_id')
                    ->orWhere('academic_period_id', $context->version->timetable->academic_period_id);
            })
            ->whereNull('academic_unit_id')
            ->orderBy('priority')
            ->get();
        $result = ConstraintResult::empty();

        foreach ($this->handlers as $handler) {
            $configuration = $this->configurationFor($handler, $configurations, $context);

            if ($configuration !== null && ! $configuration->is_enabled) {
                continue;
            }

            if (! $handler->supports($context, $configuration)) {
                continue;
            }

            $handlerResult = $handler->evaluate($context, $configuration);
            $definition = $configuration?->definition;

            $isMandatory = $definition === null
                ? $this->isMandatory($handler->code())
                : $definition->is_mandatory;

            if ($configuration !== null
                && $configuration->severity !== $handler->defaultSeverity()
                && ! $isMandatory) {
                $handlerResult = $this->adjustDefaultSeverity($handlerResult, $handler->defaultSeverity(), $configuration->severity);
            }

            $weight = $configuration === null ? 1.0 : (float) $configuration->weight;
            $softIssueCount = count(array_filter(
                $handlerResult->issues,
                fn (ConstraintIssue $issue): bool => $issue->severity === ConstraintSeverity::Soft,
            ));

            $result = $result->merge($handlerResult->withScore($softIssueCount * $weight));
        }

        return $result;
    }

    /**
     * @param  Collection<int, ConstraintConfiguration>  $configurations
     */
    private function configurationFor(ConstraintHandler $handler, Collection $configurations, SchedulingContext $context): ?ConstraintConfiguration
    {
        $matches = $configurations->filter(fn (ConstraintConfiguration $configuration): bool => $configuration->definition?->code === $handler->code());
        $periodId = $context->version->timetable->academic_period_id;

        return $matches->first(fn (ConstraintConfiguration $configuration): bool => $configuration->academic_period_id === $periodId)
            ?? $matches->first(fn (ConstraintConfiguration $configuration): bool => $configuration->academic_period_id === null);
    }

    private function adjustDefaultSeverity(ConstraintResult $result, ConstraintSeverity $default, ConstraintSeverity $configured): ConstraintResult
    {
        return new ConstraintResult(array_map(
            fn (ConstraintIssue $issue): ConstraintIssue => $issue->severity === $default
                ? $issue->withSeverity($configured)
                : $issue,
            $result->issues,
        ));
    }

    private function isMandatory(string $code): bool
    {
        return collect(self::definitions())
            ->firstWhere('code', $code)['is_mandatory'] ?? false;
    }

    /**
     * @param  class-string<ConstraintHandler>  $handler
     * @return array{code: string, name: string, handler: class-string<ConstraintHandler>, default_severity: string, configuration_schema_version: int, is_mandatory: bool}
     */
    private static function definition(string $code, string $name, string $handler, ConstraintSeverity $severity, bool $mandatory): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'handler' => $handler,
            'default_severity' => $severity->value,
            'configuration_schema_version' => 1,
            'is_mandatory' => $mandatory,
        ];
    }
}
