<?php

namespace App\Http\Controllers\Scheduling;

use App\Approvals\SubmitTimetableForApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\SubmitTimetableForApprovalRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Scheduling\CloneTimetableVersion;
use App\Scheduling\PublishTimetableVersion;
use App\Scheduling\RollbackTimetableVersion;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TimetableVersionController extends Controller
{
    public function cloneVersion(
        Request $request,
        Organization $currentOrganization,
        Timetable $timetable,
        TimetableVersion $timetableVersion,
        CloneTimetableVersion $clone,
    ): JsonResponse {
        $this->ensureRouteContext($currentOrganization, $timetable, $timetableVersion);

        $version = $clone->handle($timetableVersion, $request->user());

        return $this->versionResponse($request, $version);
    }

    public function submitVersion(
        SubmitTimetableForApprovalRequest $request,
        Organization $currentOrganization,
        Timetable $timetable,
        TimetableVersion $timetableVersion,
        SubmitTimetableForApproval $submit,
    ): JsonResponse {
        $this->ensureRouteContext($currentOrganization, $timetable, $timetableVersion);

        $workflow = DB::table('approval_workflows')
            ->where('organization_id', $currentOrganization->getKey())
            ->where('public_id', $request->workflowPublicId())
            ->where('is_active', true)
            ->firstOrFail();
        $workflowVersion = DB::table('approval_workflow_versions')
            ->where('organization_id', $currentOrganization->getKey())
            ->where('approval_workflow_id', $workflow->id)
            ->whereNotNull('activated_at')
            ->orderByDesc('version_number')
            ->firstOrFail();

        try {
            $instanceId = $submit->handle(
                $timetableVersion,
                (int) $workflowVersion->id,
                $request->user(),
            );
        } catch (DomainException $exception) {
            return ApiResponse::error(
                request: $request,
                code: 'invalid_version_transition',
                message: $exception->getMessage(),
                status: 422,
            );
        }

        $timetableVersion->refresh();

        return ApiResponse::success($request, [
            'type' => 'timetable_version_submitted',
            'attributes' => [
                'id' => $timetableVersion->public_id,
                'status' => $timetableVersion->status->value,
            ],
        ]);
    }

    public function publishVersion(
        Request $request,
        Organization $currentOrganization,
        Timetable $timetable,
        TimetableVersion $timetableVersion,
        PublishTimetableVersion $publish,
    ): JsonResponse {
        $this->ensureRouteContext($currentOrganization, $timetable, $timetableVersion);

        try {
            $version = $publish->handle($timetableVersion, $request->user());
        } catch (DomainException $exception) {
            return ApiResponse::error(
                request: $request,
                code: 'invalid_version_transition',
                message: $exception->getMessage(),
                status: 422,
            );
        }

        return $this->versionResponse($request, $version);
    }

    public function rollbackVersion(
        Request $request,
        Organization $currentOrganization,
        Timetable $timetable,
        TimetableVersion $timetableVersion,
        RollbackTimetableVersion $rollback,
    ): JsonResponse {
        $this->ensureRouteContext($currentOrganization, $timetable, $timetableVersion);

        try {
            $version = $rollback->handle($timetableVersion, $request->user());
        } catch (DomainException $exception) {
            return ApiResponse::error(
                request: $request,
                code: 'invalid_version_transition',
                message: $exception->getMessage(),
                status: 422,
            );
        }

        return $this->versionResponse($request, $version);
    }

    private function ensureRouteContext(
        Organization $organization,
        Timetable $timetable,
        TimetableVersion $version,
    ): void {
        if ($timetable->organization_id !== $organization->getKey()
            || $version->organization_id !== $organization->getKey()
            || $version->timetable_id !== $timetable->getKey()) {
            abort(404);
        }
    }

    private function versionResponse(Request $request, TimetableVersion $version): JsonResponse
    {
        return ApiResponse::success($request, [
            'type' => 'timetable_version',
            'attributes' => $this->versionAttributes($version),
        ]);
    }

    /** @return array<string, mixed> */
    private function versionAttributes(TimetableVersion $version): array
    {
        return [
            'id' => $version->public_id,
            'number' => (int) $version->version_number,
            'status' => $version->status->value,
            'entry_count' => $version->entries()->count(),
            'created_at' => $version->created_at?->toISOString(),
            'submitted_at' => $version->submitted_at?->toISOString(),
            'published_at' => $version->published_at?->toISOString(),
        ];
    }
}
