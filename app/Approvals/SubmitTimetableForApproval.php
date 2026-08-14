<?php

namespace App\Approvals;

use App\Enums\TimetableVersionStatus;
use App\Models\TimetableVersion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubmitTimetableForApproval
{
    public function handle(TimetableVersion $version, int $workflowVersionId, User $actor): int
    {
        return DB::transaction(function () use ($version, $workflowVersionId, $actor): int {
            $version = TimetableVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if (! $version->status->isEditable()) {
                throw new DomainException('Only an editable timetable version may be submitted.');
            }

            $workflowVersion = DB::table('approval_workflow_versions')
                ->where('id', $workflowVersionId)
                ->where('organization_id', $version->organization_id)
                ->first();

            if ($workflowVersion === null) {
                throw new DomainException('The approval workflow does not belong to this organization.');
            }

            $steps = DB::table('approval_workflow_steps')
                ->where('approval_workflow_version_id', $workflowVersionId)
                ->orderBy('sequence')
                ->get();

            if ($steps->isEmpty()) {
                throw new DomainException('The approval workflow must contain at least one step.');
            }

            $instanceId = DB::table('approval_instances')->insertGetId([
                'organization_id' => $version->organization_id,
                'timetable_version_id' => $version->id,
                'approval_workflow_version_id' => $workflowVersionId,
                'status' => 'pending',
                'submitted_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($steps as $step) {
                DB::table('approval_instance_steps')->insert([
                    'organization_id' => $version->organization_id,
                    'approval_instance_id' => $instanceId,
                    'sequence' => $step->sequence,
                    'label' => $step->label,
                    'minimum_approvals' => $step->minimum_approvals,
                    'allow_self_approval' => $step->allow_self_approval,
                    'status' => $step->sequence === $steps->first()->sequence ? 'active' : 'pending',
                    'signatory_slot' => $step->signatory_slot,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $version->update([
                'status' => TimetableVersionStatus::InReview,
                'submitted_at' => now(),
            ]);

            return $instanceId;
        }, attempts: 3);
    }
}
