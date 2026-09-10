<?php

namespace App\Http\Controllers\Approvals;

use App\Approvals\ActivateApprovalWorkflowVersion;
use App\Approvals\ApprovalWorkflowAuthorizer;
use App\Approvals\CreateApprovalWorkflowVersion;
use App\Approvals\DecideTimetableApproval;
use App\Approvals\RetireApprovalWorkflow;
use App\Approvals\SignatoryProfileService;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalInstanceStatus;
use App\Enums\ApprovalInstanceStepStatus;
use App\Enums\OrganizationPermission;
use App\Exceptions\ScheduleConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approvals\DecideApprovalRequest;
use App\Http\Requests\Approvals\StoreApprovalWorkflowRequest;
use App\Http\Requests\Approvals\StoreSignatoryProfileRequest;
use App\Http\Requests\Approvals\UpdateSignatoryProfileRequest;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitClosure;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\SignatoryProfile;
use App\Models\TimetableVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApprovalController extends Controller
{
    public function inbox(Request $request, Organization $currentOrganization): Response
    {
        $actor = $this->actor($request);
        $canManageApprovals = $this->canManage($currentOrganization, $actor);
        $instances = DB::table('approval_instances as instances')
            ->join('timetable_versions', 'timetable_versions.id', '=', 'instances.timetable_version_id')
            ->join('timetables', 'timetables.id', '=', 'timetable_versions.timetable_id')
            ->join('approval_workflow_versions', 'approval_workflow_versions.id', '=', 'instances.approval_workflow_version_id')
            ->join('approval_workflows', 'approval_workflows.id', '=', 'approval_workflow_versions.approval_workflow_id')
            ->leftJoin('users as submitters', 'submitters.id', '=', 'instances.submitted_by')
            ->where('instances.organization_id', $currentOrganization->getKey())
            ->orderByRaw("case when instances.status = 'pending' then 0 else 1 end")
            ->orderByDesc('instances.updated_at')
            ->limit(100)
            ->get([
                'instances.id as instance_id',
                'instances.status',
                'instances.submitted_by',
                'instances.created_at',
                'instances.updated_at',
                'timetable_versions.public_id as timetable_version_id',
                'timetable_versions.version_number',
                'timetable_versions.status as version_status',
                'timetables.public_id as timetable_id',
                'timetables.name as timetable_name',
                'approval_workflows.public_id as workflow_id',
                'approval_workflows.name as workflow_name',
                'approval_workflow_versions.version_number as workflow_version',
                'approval_workflow_versions.require_distinct_approvers',
                'submitters.name as submitter_name',
            ]);

        $instanceIds = $instances->pluck('instance_id')->map(fn (mixed $id): int => (int) $id)->all();
        $steps = $instanceIds === []
            ? collect()
            : DB::table('approval_instance_steps as steps')
                ->leftJoin('academic_units', function (JoinClause $join) use ($currentOrganization): void {
                    $join->on('academic_units.id', '=', 'steps.academic_unit_id')
                        ->where('academic_units.organization_id', $currentOrganization->getKey());
                })
                ->where('steps.organization_id', $currentOrganization->getKey())
                ->whereIn('steps.approval_instance_id', $instanceIds)
                ->orderBy('steps.sequence')
                ->get([
                    'steps.id',
                    'steps.approval_instance_id',
                    'steps.sequence',
                    'steps.label',
                    'steps.status',
                    'steps.minimum_approvals',
                    'steps.allow_self_approval',
                    'steps.approver_selector_type',
                    'steps.required_permission',
                    'steps.approver_role_codes',
                    'steps.signatory_slot',
                    'steps.academic_unit_id as academic_unit_internal_id',
                    'academic_units.public_id as academic_unit_id',
                    'academic_units.name as academic_unit_name',
                ])->groupBy('approval_instance_id');
        $stepIds = $steps->flatten(1)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $actions = $stepIds === []
            ? collect()
            : DB::table('approval_actions as actions')
                ->leftJoin('users', 'users.id', '=', 'actions.actor_user_id')
                ->join('approval_instance_steps', 'approval_instance_steps.id', '=', 'actions.approval_instance_step_id')
                ->where('actions.organization_id', $currentOrganization->getKey())
                ->whereIn('actions.approval_instance_step_id', $stepIds)
                ->orderBy('actions.acted_at')
                ->get([
                    'actions.approval_instance_step_id',
                    'actions.decision',
                    'actions.comment',
                    'actions.signatory_name',
                    'actions.signatory_position',
                    'actions.signatory_academic_unit',
                    'actions.signature_checksum',
                    'actions.acted_at',
                    'users.name as actor_name',
                ])->groupBy('approval_instance_step_id');

        return Inertia::render('approvals/Inbox', [
            'canManageApprovals' => $canManageApprovals,
            'instances' => $instances->map(function (stdClass $instance) use ($actor, $currentOrganization, $steps, $actions): array {
                /** @var Collection<int, stdClass> $instanceSteps */
                $instanceSteps = $steps->get((int) $instance->instance_id, collect());
                $activeStep = $instanceSteps->first(fn (stdClass $step): bool => $step->status === ApprovalInstanceStepStatus::Active->value);

                return [
                    'id' => (string) $instance->timetable_version_id,
                    'timetable_id' => (string) $instance->timetable_id,
                    'timetable_name' => (string) $instance->timetable_name,
                    'version_number' => (int) $instance->version_number,
                    'version_status' => (string) $instance->version_status,
                    'status' => (string) $instance->status,
                    'workflow_id' => (string) $instance->workflow_id,
                    'workflow_name' => (string) $instance->workflow_name,
                    'workflow_version' => (int) $instance->workflow_version,
                    'submitter_name' => $instance->submitter_name === null ? 'Unknown member' : (string) $instance->submitter_name,
                    'submitted_at' => $this->isoDate($instance->created_at),
                    'updated_at' => $this->isoDate($instance->updated_at),
                    'can_decide' => $this->canDecide($instance, $activeStep, $currentOrganization, $actor),
                    'steps' => $instanceSteps->map(function (stdClass $step) use ($actions, $currentOrganization, $actor, $instance): array {
                        $stepActions = $actions->get((int) $step->id, collect());

                        return [
                            'sequence' => (int) $step->sequence,
                            'label' => (string) $step->label,
                            'status' => (string) $step->status,
                            'selector_type' => $step->approver_selector_type === null ? null : (string) $step->approver_selector_type,
                            'required_permission' => $step->required_permission === null ? null : (string) $step->required_permission,
                            'minimum_approvals' => (int) $step->minimum_approvals,
                            'allow_self_approval' => (bool) $step->allow_self_approval,
                            'signatory_slot' => $step->signatory_slot === null ? null : (string) $step->signatory_slot,
                            'academic_unit_id' => $step->academic_unit_id === null ? null : (string) $step->academic_unit_id,
                            'academic_unit_name' => $step->academic_unit_name === null ? null : (string) $step->academic_unit_name,
                            'can_decide' => $step->status === ApprovalInstanceStepStatus::Active->value
                                && $this->canDecide($instance, $step, $currentOrganization, $actor),
                            'actions' => $stepActions->map(fn (stdClass $action): array => [
                                'actor_name' => $action->actor_name === null ? 'Removed member' : (string) $action->actor_name,
                                'decision' => (string) $action->decision,
                                'comment' => $action->comment === null ? null : (string) $action->comment,
                                'signatory_name' => $action->signatory_name === null ? null : (string) $action->signatory_name,
                                'signatory_position' => $action->signatory_position === null ? null : (string) $action->signatory_position,
                                'signatory_academic_unit' => $action->signatory_academic_unit === null ? null : (string) $action->signatory_academic_unit,
                                'has_signature' => $action->signature_checksum !== null,
                                'acted_at' => $this->isoDate($action->acted_at),
                            ])->values()->all(),
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ]);
    }

    public function workflows(Request $request, Organization $currentOrganization): Response
    {
        $actor = $this->actor($request);
        $this->authorizeManagement($currentOrganization, $actor);
        $workflowRows = DB::table('approval_workflows')
            ->where('organization_id', $currentOrganization->getKey())
            ->orderBy('name')
            ->get(['id', 'public_id', 'name', 'is_active']);
        $versionRows = DB::table('approval_workflow_versions')
            ->where('organization_id', $currentOrganization->getKey())
            ->orderByDesc('version_number')
            ->get(['id', 'approval_workflow_id', 'version_number', 'activated_at', 'require_distinct_approvers']);
        $stepRows = DB::table('approval_workflow_steps')
            ->where('organization_id', $currentOrganization->getKey())
            ->orderBy('sequence')
            ->get();
        $roleRows = DB::table('approval_step_roles')
            ->join('roles', 'roles.id', '=', 'approval_step_roles.role_id')
            ->where('approval_step_roles.organization_id', $currentOrganization->getKey())
            ->get(['approval_step_roles.approval_workflow_step_id', 'roles.code']);
        $rolesByStep = $roleRows->groupBy('approval_workflow_step_id');
        $units = AcademicUnit::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->orderBy('name')
            ->get()
            ->keyBy(fn (AcademicUnit $unit): int => $unit->getKey());

        return Inertia::render('approvals/Workflows', [
            'workflows' => $workflowRows->map(function (stdClass $workflow) use ($versionRows, $stepRows, $rolesByStep, $units): array {
                $versions = $versionRows->where('approval_workflow_id', $workflow->id)->map(function (stdClass $version) use ($stepRows, $rolesByStep, $units): array {
                    $versionSteps = $stepRows->where('approval_workflow_version_id', $version->id)->map(function (stdClass $step) use ($rolesByStep, $units): array {
                        $unit = $step->academic_unit_id === null ? null : $units->get((int) $step->academic_unit_id);

                        return [
                            'sequence' => (int) $step->sequence,
                            'label' => (string) $step->label,
                            'selector_type' => (string) $step->approver_selector_type,
                            'required_permission' => $step->required_permission === null ? null : (string) $step->required_permission,
                            'role_codes' => $rolesByStep->get((int) $step->id, collect())->pluck('code')->map(fn (mixed $code): string => (string) $code)->values()->all(),
                            'minimum_approvals' => (int) $step->minimum_approvals,
                            'allow_self_approval' => (bool) $step->allow_self_approval,
                            'signatory_slot' => $step->signatory_slot === null ? null : (string) $step->signatory_slot,
                            'academic_unit_id' => $unit?->public_id,
                            'academic_unit_name' => $unit?->name,
                        ];
                    })->values()->all();

                    return [
                        'version' => (int) $version->version_number,
                        'activated_at' => $this->isoDate($version->activated_at),
                        'is_active' => $version->activated_at !== null,
                        'require_distinct_approvers' => (bool) $version->require_distinct_approvers,
                        'steps' => $versionSteps,
                    ];
                })->values()->all();

                return [
                    'id' => (string) $workflow->public_id,
                    'name' => (string) $workflow->name,
                    'is_active' => (bool) $workflow->is_active,
                    'versions' => $versions,
                ];
            })->values()->all(),
            'units' => $units->values()->map(fn (AcademicUnit $unit): array => [
                'id' => $unit->public_id,
                'name' => $unit->name,
                'code' => $unit->code,
            ])->all(),
            'roles' => $currentOrganization->roles()->orderBy('name')->get(['code', 'name'])->map(fn ($role): array => [
                'code' => $role->code,
                'name' => $role->name,
            ])->values()->all(),
            'permissions' => array_map(fn (OrganizationPermission $permission): array => [
                'value' => $permission->value,
                'label' => $permission->label(),
            ], OrganizationPermission::cases()),
        ]);
    }

    public function signatories(Request $request, Organization $currentOrganization): Response
    {
        Gate::forUser($this->actor($request))->authorize('viewAny', [SignatoryProfile::class, $currentOrganization]);
        $profiles = DB::table('signatory_profiles as profiles')
            ->join('users', 'users.id', '=', 'profiles.user_id')
            ->join('organization_members', function (JoinClause $join) use ($currentOrganization): void {
                $join->on('organization_members.user_id', '=', 'profiles.user_id')
                    ->where('organization_members.organization_id', $currentOrganization->getKey());
            })
            ->leftJoin('academic_units', function (JoinClause $join) use ($currentOrganization): void {
                $join->on('academic_units.id', '=', 'profiles.academic_unit_id')
                    ->where('academic_units.organization_id', $currentOrganization->getKey());
            })
            ->where('profiles.organization_id', $currentOrganization->getKey())
            ->whereNull('profiles.deleted_at')
            ->orderBy('users.name')
            ->orderBy('profiles.name')
            ->get([
                'profiles.public_id',
                'profiles.name',
                'profiles.position',
                'profiles.academic_unit_name',
                'profiles.valid_from',
                'profiles.valid_until',
                'profiles.signature_path',
                'organization_members.public_id as membership_id',
                'users.name as user_name',
                'users.email as user_email',
                'academic_units.public_id as academic_unit_id',
            ]);

        return Inertia::render('approvals/Signatories', [
            'profiles' => $profiles->map(fn (stdClass $profile): array => [
                'id' => (string) $profile->public_id,
                'membership_id' => (string) $profile->membership_id,
                'user_name' => (string) $profile->user_name,
                'user_email' => (string) $profile->user_email,
                'name' => (string) $profile->name,
                'position' => (string) $profile->position,
                'academic_unit_id' => $profile->academic_unit_id === null ? null : (string) $profile->academic_unit_id,
                'academic_unit_name' => $profile->academic_unit_name === null ? null : (string) $profile->academic_unit_name,
                'valid_from' => $this->dateInput($profile->valid_from)?->toDateString(),
                'valid_until' => $this->dateInput($profile->valid_until)?->toDateString(),
                'has_signature' => $profile->signature_path !== null,
                'signature_download_url' => $profile->signature_path === null
                    ? null
                    : URL::temporarySignedRoute('approvals.signatories.signature.download', now()->addMinutes(5), [
                        'current_organization' => $currentOrganization->slug,
                        'signatory_profile' => (string) $profile->public_id,
                    ]),
            ])->values()->all(),
            'members' => $currentOrganization->memberships()->with('user')->orderBy('id')->get()->map(fn (Membership $membership): array => [
                'id' => $membership->public_id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
            ])->values()->all(),
            'units' => AcademicUnit::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->orderBy('name')
                ->get()
                ->map(fn (AcademicUnit $unit): array => [
                    'id' => $unit->public_id,
                    'name' => $unit->name,
                ])->values()->all(),
        ]);
    }

    public function downloadSignatorySignature(
        Request $request,
        Organization $currentOrganization,
        SignatoryProfile $signatoryProfile,
    ): StreamedResponse {
        abort_unless($signatoryProfile->organization_id === $currentOrganization->getKey(), 404);
        Gate::forUser($this->actor($request))->authorize('view', $signatoryProfile);

        $signaturePath = $signatoryProfile->signature_path;
        abort_if($signaturePath === null || ! Storage::disk(SignatoryProfile::SIGNATURE_DISK)->exists($signaturePath), 404);

        return Storage::disk(SignatoryProfile::SIGNATURE_DISK)->download(
            $signaturePath,
            'signature-'.$signatoryProfile->public_id.'.'.pathinfo($signaturePath, PATHINFO_EXTENSION),
        );
    }

    public function storeWorkflow(
        StoreApprovalWorkflowRequest $request,
        Organization $currentOrganization,
        CreateApprovalWorkflowVersion $create,
    ): RedirectResponse {
        $validatedSteps = $request->validated('steps');
        $steps = [];

        foreach (is_array($validatedSteps) ? array_values($validatedSteps) : [] as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $roleCodes = $step['role_codes'] ?? [];
            $steps[] = [
                'sequence' => $index + 1,
                'label' => (string) ($step['label'] ?? ''),
                'academic_unit_public_id' => is_string($step['academic_unit_id'] ?? null) ? $step['academic_unit_id'] : null,
                'approver_selector_type' => (string) ($step['approver_selector_type'] ?? ''),
                'required_permission' => is_string($step['required_permission'] ?? null) ? $step['required_permission'] : null,
                'role_codes' => is_array($roleCodes) ? array_values($roleCodes) : [],
                'minimum_approvals' => (int) ($step['minimum_approvals'] ?? 1),
                'allow_self_approval' => (bool) ($step['allow_self_approval'] ?? false),
                'signatory_slot' => is_string($step['signatory_slot'] ?? null) ? $step['signatory_slot'] : null,
            ];
        }

        try {
            $create->handle(
                organization: $currentOrganization,
                actor: $this->actor($request),
                name: (string) $request->validated('name'),
                steps: $steps,
                requireDistinctApprovers: $request->boolean('require_distinct_approvers'),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['steps' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workflow draft created.')]);

        return to_route('approvals.workflows', ['current_organization' => $currentOrganization->slug]);
    }

    public function activateWorkflow(
        Request $request,
        Organization $currentOrganization,
        string $workflow,
        string $version,
        ActivateApprovalWorkflowVersion $activate,
    ): RedirectResponse {
        $versionRow = $this->workflowVersion($currentOrganization, $workflow, $version);
        $activate->handle($currentOrganization, $this->actor($request), (int) $versionRow->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workflow version activated.')]);

        return to_route('approvals.workflows', ['current_organization' => $currentOrganization->slug]);
    }

    public function retireWorkflow(
        Request $request,
        Organization $currentOrganization,
        string $workflow,
        RetireApprovalWorkflow $retire,
    ): RedirectResponse {
        $workflowRow = DB::table('approval_workflows')
            ->where('organization_id', $currentOrganization->getKey())
            ->where('public_id', $workflow)
            ->firstOrFail();
        $retire->handle($currentOrganization, $this->actor($request), (int) $workflowRow->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workflow retired.')]);

        return to_route('approvals.workflows', ['current_organization' => $currentOrganization->slug]);
    }

    public function decide(
        DecideApprovalRequest $request,
        Organization $currentOrganization,
        TimetableVersion $timetableVersion,
        DecideTimetableApproval $decide,
    ): RedirectResponse {
        abort_unless($timetableVersion->organization_id === $currentOrganization->getKey(), 404);
        $instanceId = DB::table('approval_instances')
            ->where('organization_id', $currentOrganization->getKey())
            ->where('timetable_version_id', $timetableVersion->getKey())
            ->value('id');

        abort_if($instanceId === null, 404);

        try {
            $decide->handle(
                approvalInstanceId: (int) $instanceId,
                decision: ApprovalDecision::from((string) $request->validated('decision')),
                idempotencyKey: (string) $request->validated('idempotency_key'),
                actor: $this->actor($request),
                comment: is_string($request->validated('comment')) ? $request->validated('comment') : null,
            );
        } catch (ScheduleConflictException|DomainException $exception) {
            throw ValidationException::withMessages(['decision' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Approval decision recorded.')]);

        return to_route('approvals.inbox', ['current_organization' => $currentOrganization->slug]);
    }

    public function storeSignatory(
        StoreSignatoryProfileRequest $request,
        Organization $currentOrganization,
        SignatoryProfileService $profiles,
    ): RedirectResponse {
        $membership = Membership::query()
            ->where('organization_id', $currentOrganization->getKey())
            ->where('public_id', $request->validated('membership_id'))
            ->firstOrFail();
        $unit = $this->academicUnit($currentOrganization, $request->validated('academic_unit_id'));
        $signatureImage = $request->file('signature_image');

        $profiles->create(
            organization: $currentOrganization,
            actor: $this->actor($request),
            signatory: $membership->user()->firstOrFail(),
            name: (string) $request->validated('name'),
            position: (string) $request->validated('position'),
            academicUnit: $unit,
            validFrom: $this->dateInput($request->validated('valid_from')),
            validUntil: $this->dateInput($request->validated('valid_until')),
            signatureImage: $signatureImage instanceof UploadedFile ? $signatureImage : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signatory profile created.')]);

        return to_route('approvals.signatories', ['current_organization' => $currentOrganization->slug]);
    }

    public function updateSignatory(
        UpdateSignatoryProfileRequest $request,
        Organization $currentOrganization,
        SignatoryProfile $signatoryProfile,
        SignatoryProfileService $profiles,
    ): RedirectResponse {
        abort_unless($signatoryProfile->organization_id === $currentOrganization->getKey(), 404);
        $unit = $this->academicUnit($currentOrganization, $request->validated('academic_unit_id'));
        $signatureImage = $request->file('signature_image');

        $profiles->update(
            organization: $currentOrganization,
            actor: $this->actor($request),
            profile: $signatoryProfile,
            name: (string) $request->validated('name'),
            position: (string) $request->validated('position'),
            academicUnit: $unit,
            validFrom: $this->dateInput($request->validated('valid_from')),
            validUntil: $this->dateInput($request->validated('valid_until')),
            signatureImage: $signatureImage instanceof UploadedFile ? $signatureImage : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signatory profile updated.')]);

        return to_route('approvals.signatories', ['current_organization' => $currentOrganization->slug]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function authorizeManagement(Organization $organization, User $actor): void
    {
        app(ApprovalWorkflowAuthorizer::class)->authorize($organization, $actor);
    }

    private function canManage(Organization $organization, User $actor): bool
    {
        try {
            $this->authorizeManagement($organization, $actor);
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    private function workflowVersion(Organization $organization, string $workflow, string $version): stdClass
    {
        abort_unless(Str::isUuid($workflow) && ctype_digit($version), 404);

        return DB::table('approval_workflow_versions')
            ->join('approval_workflows', 'approval_workflows.id', '=', 'approval_workflow_versions.approval_workflow_id')
            ->where('approval_workflow_versions.organization_id', $organization->getKey())
            ->where('approval_workflows.organization_id', $organization->getKey())
            ->where('approval_workflows.public_id', $workflow)
            ->where('approval_workflow_versions.version_number', (int) $version)
            ->firstOrFail(['approval_workflow_versions.id']);
    }

    private function academicUnit(Organization $organization, mixed $publicId): ?AcademicUnit
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        abort_unless(is_string($publicId) && Str::isUuid($publicId), 404);

        return AcademicUnit::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function dateInput(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && trim($value) !== '' ? CarbonImmutable::parse($value) : null;
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value->toISOString()
            : CarbonImmutable::parse((string) $value)->toISOString();
    }

    private function canDecide(stdClass $instance, ?stdClass $step, Organization $organization, User $actor): bool
    {
        if ($instance->status !== ApprovalInstanceStatus::Pending->value || $step === null) {
            return false;
        }

        if (! (bool) $step->allow_self_approval && (int) $instance->submitted_by === $actor->getKey()) {
            return false;
        }

        $unit = $step->academic_unit_internal_id === null
            ? null
            : AcademicUnit::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey((int) $step->academic_unit_internal_id)
                ->first();
        $selectorType = $step->approver_selector_type === null ? null : (string) $step->approver_selector_type;
        $eligible = false;

        if ($selectorType === 'permission') {
            $permission = OrganizationPermission::tryFrom((string) $step->required_permission);
            $eligible = $permission !== null && $actor->hasOrganizationPermission($organization, $permission, $unit);
        }

        if ($selectorType === 'role') {
            $roleCodes = json_decode((string) ($step->approver_role_codes ?? '[]'), true);
            $eligible = is_array($roleCodes) && $this->hasRoleSelector($organization, $actor, $unit, $roleCodes);
        }

        if (! $eligible || ! (bool) ($instance->require_distinct_approvers ?? false)) {
            return $eligible;
        }

        return ! DB::table('approval_actions')
            ->join('approval_instance_steps', 'approval_instance_steps.id', '=', 'approval_actions.approval_instance_step_id')
            ->where('approval_actions.organization_id', $organization->getKey())
            ->where('approval_instance_steps.organization_id', $organization->getKey())
            ->where('approval_instance_steps.approval_instance_id', $instance->instance_id)
            ->where('approval_actions.actor_user_id', $actor->getKey())
            ->exists();
    }

    private function hasRoleSelector(Organization $organization, User $actor, ?AcademicUnit $unit, mixed $roleCodes): bool
    {
        $roleCodes = is_array($roleCodes)
            ? array_values(array_filter($roleCodes, fn (mixed $code): bool => is_string($code) && $code !== ''))
            : [];

        if ($roleCodes === []) {
            return false;
        }

        $query = DB::table('membership_role_assignments')
            ->join('organization_members', 'organization_members.id', '=', 'membership_role_assignments.membership_id')
            ->join('roles', 'roles.id', '=', 'membership_role_assignments.role_id')
            ->where('membership_role_assignments.organization_id', $organization->getKey())
            ->where('organization_members.organization_id', $organization->getKey())
            ->where('organization_members.user_id', $actor->getKey())
            ->where('roles.organization_id', $organization->getKey())
            ->whereIn('roles.code', $roleCodes);

        if ($unit === null) {
            return $query->whereNull('membership_role_assignments.academic_unit_id')->exists();
        }

        $scopedUnitIds = AcademicUnitClosure::query()
            ->where('organization_id', $organization->getKey())
            ->where('descendant_id', $unit->getKey())
            ->pluck('ancestor_id')
            ->push($unit->getKey())
            ->unique()
            ->values()
            ->all();

        return $query->where(function (Builder $query) use ($scopedUnitIds): void {
            $query->whereNull('membership_role_assignments.academic_unit_id')
                ->orWhereIn('membership_role_assignments.academic_unit_id', $scopedUnitIds);
        })->exists();
    }
}
