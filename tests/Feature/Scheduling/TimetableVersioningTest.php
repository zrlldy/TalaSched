<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\TimetableVersionStatus;
use App\Exceptions\ScheduleConflictException;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\ScheduleEntry;
use App\Models\ScheduleEntryException;
use App\Models\ScheduleEntryResource;
use App\Models\SchedulingResource;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Scheduling\CloneTimetableVersion;
use App\Scheduling\PublishTimetableVersion;
use App\Scheduling\RollbackTimetableVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->user->currentOrganization;
    grantTimetableVersioningEntitlement($this->organization);
    $year = AcademicYear::create([
        'organization_id' => $this->organization->id,
        'name' => '2026-2027',
        'starts_on' => '2026-06-01',
        'ends_on' => '2027-05-31',
        'status' => 'active',
    ]);
    $period = AcademicPeriod::create([
        'organization_id' => $this->organization->id,
        'academic_year_id' => $year->id,
        'name' => 'Term 1',
        'kind' => AcademicPeriodKind::Term,
        'sequence' => 1,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-09-30',
    ]);
    $this->timetable = Timetable::create([
        'organization_id' => $this->organization->id,
        'academic_year_id' => $year->id,
        'academic_period_id' => $period->id,
        'name' => 'Master Timetable',
        'timezone' => 'Asia/Manila',
        'scheduling_granularity' => 30,
    ]);
});

test('a timetable version is cloned as a new editable snapshot', function () {
    $source = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Published,
        'created_by' => $this->user->id,
    ]);

    $clone = app(CloneTimetableVersion::class)->handle($source, $this->user);

    expect($clone->version_number)->toBe(2)
        ->and($clone->based_on_version_id)->toBe($source->id)
        ->and($clone->status)->toBe(TimetableVersionStatus::Draft);
});

test('version management routes expose clone publish and rollback transitions', function () {
    $source = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Published,
        'created_by' => $this->user->id,
    ]);

    $cloneResponse = $this->actingAs($this->user)->postJson(route(
        'scheduling.timetables.versions.clone',
        [$this->organization, $this->timetable, $source],
    ));
    $cloneResponse->assertSuccessful()
        ->assertJson(fn (AssertableJson $json): AssertableJson => $json
            ->where('data.type', 'timetable_version')
            ->where('data.attributes.status', TimetableVersionStatus::Draft->value)
            ->etc());
    $clone = TimetableVersion::query()->where('status', TimetableVersionStatus::Draft)->firstOrFail();

    $approved = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 3,
        'status' => TimetableVersionStatus::Approved,
        'created_by' => $this->user->id,
    ]);
    $publishResponse = $this->actingAs($this->user)->postJson(route(
        'scheduling.timetables.versions.publish',
        [$this->organization, $this->timetable, $approved],
    ));
    $publishResponse->assertSuccessful()
        ->assertJsonPath('data.attributes.status', TimetableVersionStatus::Published->value);

    $rollbackResponse = $this->actingAs($this->user)->postJson(route(
        'scheduling.timetables.versions.rollback',
        [$this->organization, $this->timetable, $approved],
    ));
    $rollbackResponse->assertSuccessful()
        ->assertJsonPath('data.attributes.status', TimetableVersionStatus::Draft->value)
        ->assertJsonPath('data.attributes.number', 4);

    expect($source->fresh()->status)->toBe(TimetableVersionStatus::Superseded)
        ->and($clone->fresh()->status)->toBe(TimetableVersionStatus::Draft);
});

test('version submit route accepts an organization public workflow identifier', function () {
    grantApprovalWorkflowsEntitlement($this->organization);
    $workflowPublicId = (string) Str::uuid();
    $timestamp = now();
    $workflowId = DB::table('approval_workflows')->insertGetId([
        'organization_id' => $this->organization->id,
        'public_id' => $workflowPublicId,
        'name' => 'Version review',
        'is_active' => true,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $workflowVersionId = DB::table('approval_workflow_versions')->insertGetId([
        'organization_id' => $this->organization->id,
        'approval_workflow_id' => $workflowId,
        'version_number' => 1,
        'activated_at' => $timestamp,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('approval_workflow_steps')->insert([
        'organization_id' => $this->organization->id,
        'approval_workflow_version_id' => $workflowVersionId,
        'sequence' => 1,
        'label' => 'Scheduling review',
        'approver_selector_type' => 'permission',
        'required_permission' => 'manage_scheduling',
        'minimum_approvals' => 1,
        'allow_self_approval' => false,
        'signatory_slot' => 'registrar',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $version = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Draft,
        'created_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->postJson(route(
        'scheduling.timetables.versions.submit',
        [$this->organization, $this->timetable, $version],
    ), [
        'workflow_id' => $workflowPublicId,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.type', 'timetable_version_submitted')
        ->assertJsonPath('data.attributes.id', $version->public_id)
        ->assertJsonPath('data.attributes.status', TimetableVersionStatus::InReview->value);

    expect(DB::table('approval_instances')->where('timetable_version_id', $version->id)->count())->toBe(1);
});

test('a timetable version clone copies dated exceptions and replacement resources', function () {
    $source = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Published,
        'created_by' => $this->user->id,
    ]);
    $entry = ScheduleEntry::factory()->create([
        'organization_id' => $this->organization->id,
        'timetable_version_id' => $source->id,
    ]);
    $exception = ScheduleEntryException::create([
        'organization_id' => $this->organization->id,
        'schedule_entry_id' => $entry->id,
        'date' => '2026-08-24',
        'action' => 'replaced',
        'starts_at_minute' => 600,
        'ends_at_minute' => 690,
        'reason' => 'Make-up room and teacher',
    ]);
    $exception->resources()->sync(
        $entry->resources
            ->mapWithKeys(fn (ScheduleEntryResource $assignment): array => [
                $assignment->scheduling_resource_id => [
                    'organization_id' => $this->organization->id,
                    'role' => $assignment->role->value,
                ],
            ])
            ->all(),
    );

    $clone = app(CloneTimetableVersion::class)->handle($source, $this->user);
    $clonedEntry = $clone->entries()->firstOrFail();
    $clonedException = $clonedEntry->exceptions()->with('resources')->firstOrFail();

    expect($clonedEntry->logical_id)->toBe($entry->logical_id)
        ->and($clonedEntry->public_id)->not->toBe($entry->public_id)
        ->and($clonedEntry->timetable_version_id)->toBe($clone->id)
        ->and($clonedException->public_id)->not->toBe($exception->public_id)
        ->and($clonedException->schedule_entry_id)->toBe($clonedEntry->id)
        ->and($clonedException->date->toDateString())->toBe('2026-08-24')
        ->and($clonedException->action->value)->toBe('replaced')
        ->and($clonedException->starts_at_minute)->toBe(600)
        ->and($clonedException->ends_at_minute)->toBe(690)
        ->and($clonedException->reason)->toBe('Make-up room and teacher')
        ->and($clonedException->resources->pluck('public_id')->sort()->values()->all())
        ->toBe($exception->resources()->pluck('scheduling_resources.public_id')->sort()->values()->all());
});

test('timetable version comparison reports lineage-based additions removals and edits', function () {
    $source = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Published,
        'created_by' => $this->user->id,
    ]);
    $entryLogicalIds = [
        'moved' => (string) Str::uuid(),
        'reassigned' => (string) Str::uuid(),
        'changed' => (string) Str::uuid(),
        'removed' => (string) Str::uuid(),
    ];

    foreach ($entryLogicalIds as $logicalId) {
        ScheduleEntry::factory()->create([
            'organization_id' => $this->organization->id,
            'timetable_version_id' => $source->id,
            'logical_id' => $logicalId,
        ]);
    }

    $target = app(CloneTimetableVersion::class)->handle($source, $this->user);
    $targetEntries = $target->entries()->get()->keyBy('logical_id');
    $targetEntries->get($entryLogicalIds['moved'])->update([
        'starts_at_minute' => 600,
        'ends_at_minute' => 690,
    ]);
    $replacementResource = SchedulingResource::factory()->forOrganization($this->organization)->create();
    $targetEntries->get($entryLogicalIds['reassigned'])->resources()->firstOrFail()->update([
        'scheduling_resource_id' => $replacementResource->id,
    ]);
    $targetEntries->get($entryLogicalIds['changed'])->update(['notes' => 'Updated delivery note']);
    $targetEntries->get($entryLogicalIds['removed'])->delete();
    ScheduleEntry::factory()->create([
        'organization_id' => $this->organization->id,
        'timetable_version_id' => $target->id,
        'logical_id' => (string) Str::uuid(),
    ]);

    $response = $this->actingAs($this->user)->getJson(route(
        'scheduling.timetables.versions.compare',
        [
            'current_organization' => $this->organization,
            'timetable' => $this->timetable,
            'from_version_id' => $source->public_id,
            'to_version_id' => $target->public_id,
        ],
    ));

    $response->assertSuccessful()
        ->assertJsonPath('data.type', 'timetable_version_comparison')
        ->assertJsonPath('data.attributes.from_version.id', $source->public_id)
        ->assertJsonPath('data.attributes.to_version.id', $target->public_id)
        ->assertJsonPath('data.attributes.summary.added', 1)
        ->assertJsonPath('data.attributes.summary.removed', 1)
        ->assertJsonPath('data.attributes.summary.moved', 1)
        ->assertJsonPath('data.attributes.summary.reassigned', 1)
        ->assertJsonPath('data.attributes.summary.changed', 1)
        ->assertJsonPath('data.attributes.summary.total', 5);

    expect(collect($response->json('data.attributes.changes'))->pluck('kind')->all())
        ->toContain('added', 'removed', 'moved', 'reassigned', 'changed');
});

test('publishing atomically supersedes the previous published version', function () {
    $published = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Published,
        'created_by' => $this->user->id,
    ]);
    $approved = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 2,
        'status' => TimetableVersionStatus::Approved,
        'created_by' => $this->user->id,
    ]);

    $result = app(PublishTimetableVersion::class)->handle($approved, $this->user);

    expect($published->fresh()->status)->toBe(TimetableVersionStatus::Superseded)
        ->and($result->status)->toBe(TimetableVersionStatus::Published)
        ->and($result->published_by)->toBe($this->user->id)
        ->and($result->published_at)->not->toBeNull();
});

test('publication rejects versions whose entries fail hard constraints', function () {
    $approved = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Approved,
        'created_by' => $this->user->id,
    ]);
    $entry = ScheduleEntry::factory()->create([
        'organization_id' => $this->organization->id,
        'timetable_version_id' => $approved->id,
    ]);

    try {
        app(PublishTimetableVersion::class)->handle($approved, $this->user);
        throw new RuntimeException('Expected publication to reject invalid entries.');
    } catch (ScheduleConflictException $exception) {
        expect($exception->issues)->not->toBeEmpty()
            ->and($exception->issues[0]['details']['entry_id'])->toBe($entry->public_id);
    }

    expect($approved->fresh()->status)->toBe(TimetableVersionStatus::Approved)
        ->and(TimetableVersion::query()->where('status', TimetableVersionStatus::Published)->count())->toBe(0);
});

test('a draft version cannot be published without approval', function () {
    $draft = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Draft,
        'created_by' => $this->user->id,
    ]);

    expect(fn () => app(PublishTimetableVersion::class)->handle($draft, $this->user))
        ->toThrow(DomainException::class, 'Only an approved timetable version may be published.');
});

test('rollback clones historical versions into a new draft without mutating history', function () {
    $source = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Superseded,
        'created_by' => $this->user->id,
    ]);
    $sourcePublicId = $source->public_id;
    $sourceUpdatedAt = $source->updated_at;

    $rollback = app(RollbackTimetableVersion::class)->handle($source, $this->user);

    expect($rollback->status)->toBe(TimetableVersionStatus::Draft)
        ->and($rollback->based_on_version_id)->toBe($source->id)
        ->and($rollback->version_number)->toBe(2)
        ->and($rollback->public_id)->not->toBe($sourcePublicId)
        ->and($source->fresh()->status)->toBe(TimetableVersionStatus::Superseded)
        ->and($source->fresh()->updated_at->equalTo($sourceUpdatedAt))->toBeTrue()
        ->and(TimetableVersion::query()->count())->toBe(2);
});

test('rollback rejects editable versions', function () {
    $draft = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $this->timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Draft,
        'created_by' => $this->user->id,
    ]);

    expect(fn () => app(RollbackTimetableVersion::class)->handle($draft, $this->user))
        ->toThrow(DomainException::class, 'Only published or superseded timetable versions may be rolled back.');
    expect(TimetableVersion::query()->count())->toBe(1);
});
