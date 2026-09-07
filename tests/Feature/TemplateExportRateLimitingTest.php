<?php

use App\Enums\ExportRunPurpose;
use App\Jobs\GenerateTemplateExport;
use App\Jobs\Middleware\UseTenantContext;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\ExcelTemplate;
use App\Models\ExcelTemplateVersion;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
    grantCustomExcelTemplatesEntitlement($this->organization);
});

test('template export requests are rate limited per actor and organization', function (): void {
    [$timetableVersion, $templateVersion] = templateExportRateLimitInputs($this->organization);
    Queue::fake();

    foreach (range(1, 5) as $attempt) {
        $this
            ->actingAs($this->owner)
            ->post(route('templates.exports.store', [
                'current_organization' => $this->organization->slug,
            ]), [
                'template_version_id' => $templateVersion->public_id,
                'timetable_version_id' => $timetableVersion->public_id,
                'purpose' => ExportRunPurpose::Preview->value,
            ])
            ->assertRedirect(route('templates.index', [
                'current_organization' => $this->organization->slug,
            ]));
    }

    $this
        ->actingAs($this->owner)
        ->post(route('templates.exports.store', [
            'current_organization' => $this->organization->slug,
        ]), [
            'template_version_id' => $templateVersion->public_id,
            'timetable_version_id' => $timetableVersion->public_id,
            'purpose' => ExportRunPurpose::Preview->value,
        ])
        ->assertTooManyRequests();

    Queue::assertPushed(GenerateTemplateExport::class, 5);
});

test('template export generation has an organization-scoped queue limiter', function (): void {
    $job = new GenerateTemplateExport($this->organization->public_id, 'a8c7b4f2-a513-4e92-9dc9-1b09024401f2');
    $limiter = RateLimiter::limiter('template-export-generation');

    expect($limiter)->not->toBeNull();

    $limit = $limiter($job);

    expect($limit)
        ->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe('template-export-generation|'.$this->organization->public_id)
        ->and($limit->maxAttempts)->toBe(10)
        ->and($limit->decaySeconds)->toBe(60)
        ->and(collect($job->middleware())->contains(fn (object $middleware): bool => $middleware instanceof RateLimited))->toBeTrue()
        ->and(collect($job->middleware())->contains(fn (object $middleware): bool => $middleware instanceof UseTenantContext))->toBeTrue();
});

/**
 * @return array{0: TimetableVersion, 1: ExcelTemplateVersion}
 */
function templateExportRateLimitInputs(Organization $organization): array
{
    $year = AcademicYear::factory()->forOrganization($organization)->create();
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    $timetable = Timetable::factory()->create([
        'organization_id' => $organization->getKey(),
        'academic_year_id' => $year->getKey(),
        'academic_period_id' => $period->getKey(),
    ]);
    $timetableVersion = TimetableVersion::factory()->create([
        'organization_id' => $organization->getKey(),
        'timetable_id' => $timetable->getKey(),
    ]);
    $asset = FileAsset::factory()->create([
        'organization_id' => $organization->getKey(),
        'scan_status' => FileAsset::ScanClean,
    ]);
    $template = ExcelTemplate::factory()->forOrganization($organization)->create();
    $templateVersion = ExcelTemplateVersion::factory()->forTemplate($template, $asset)->create();

    return [$timetableVersion, $templateVersion];
}
