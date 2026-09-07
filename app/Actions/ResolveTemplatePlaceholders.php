<?php

namespace App\Actions;

use App\Data\Templates\TemplatePlaceholderContext;
use App\Enums\TemplatePlaceholder;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;

class ResolveTemplatePlaceholders
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @return list<array{key: string, label: string}>
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach (TemplatePlaceholder::cases() as $placeholder) {
            $catalog[] = [
                'key' => $placeholder->value,
                'label' => $placeholder->label(),
            ];
        }

        return $catalog;
    }

    /**
     * @return array<string, string>
     */
    public function resolve(
        Organization $organization,
        TimetableVersion $timetableVersion,
        CarbonInterface $generatedAt,
    ): array {
        return $this->tenantContext->run($organization, function () use ($organization, $timetableVersion, $generatedAt): array {
            $version = TimetableVersion::query()
                ->whereKey($timetableVersion->getKey())
                ->where('organization_id', $organization->getKey())
                ->firstOrFail();
            $timetable = Timetable::query()
                ->whereKey($version->timetable_id)
                ->where('organization_id', $organization->getKey())
                ->firstOrFail();
            $period = AcademicPeriod::query()
                ->whereKey($timetable->academic_period_id)
                ->where('organization_id', $organization->getKey())
                ->firstOrFail();
            $academicYear = AcademicYear::query()
                ->whereKey($timetable->academic_year_id)
                ->where('organization_id', $organization->getKey())
                ->firstOrFail();

            return $this->values(new TemplatePlaceholderContext(
                organization: $organization,
                academicYear: $academicYear,
                academicPeriod: $period,
                timetable: $timetable,
                timetableVersion: $version,
                generatedAt: $generatedAt,
            ));
        });
    }

    /**
     * @return array<string, string>
     */
    public function values(TemplatePlaceholderContext $context): array
    {
        $timezone = $context->organization->timezone;

        return [
            TemplatePlaceholder::OrganizationName->value => $context->organization->name,
            TemplatePlaceholder::OrganizationTimezone->value => $timezone,
            TemplatePlaceholder::AcademicYearName->value => $context->academicYear->name,
            TemplatePlaceholder::AcademicPeriodName->value => $context->academicPeriod->name,
            TemplatePlaceholder::TimetableName->value => $context->timetable->name,
            TemplatePlaceholder::TimetableVersionNumber->value => (string) $context->timetableVersion->version_number,
            TemplatePlaceholder::TimetableVersionStatus->value => $context->timetableVersion->status->value,
            TemplatePlaceholder::PublishedAt->value => $context->timetableVersion->published_at?->setTimezone($timezone)->toIso8601String() ?? '',
            TemplatePlaceholder::GeneratedAt->value => $context->generatedAt->setTimezone($timezone)->toIso8601String(),
        ];
    }
}
