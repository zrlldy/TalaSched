<?php

namespace App\Actions;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalInstanceStepStatus;
use App\Models\Organization;
use App\Models\SignatoryProfile;
use App\Models\TimetableVersion;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

class ResolveTemplateSignatories
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Resolve the final approving action for each mapped signatory slot.
     *
     * @return array<string, array{name: string, position: string, signature_checksum: string|null, signature_disk: string|null, signature_path: string|null}>
     */
    public function resolve(Organization $organization, TimetableVersion $timetableVersion): array
    {
        return $this->tenantContext->run($organization, function () use ($organization, $timetableVersion): array {
            $version = TimetableVersion::query()
                ->whereKey($timetableVersion->getKey())
                ->where('organization_id', $organization->getKey())
                ->firstOrFail();

            $actions = DB::table('approval_actions')
                ->select([
                    'approval_actions.id',
                    'approval_actions.acted_at',
                    'approval_actions.signature_checksum',
                    'approval_actions.signature_disk',
                    'approval_actions.signature_path',
                    'approval_actions.signatory_name',
                    'approval_actions.signatory_position',
                    'approval_instance_steps.sequence',
                    'approval_instance_steps.signatory_slot',
                ])
                ->join('approval_instance_steps', 'approval_instance_steps.id', '=', 'approval_actions.approval_instance_step_id')
                ->join('approval_instances', 'approval_instances.id', '=', 'approval_instance_steps.approval_instance_id')
                ->where('approval_actions.organization_id', $organization->getKey())
                ->where('approval_instance_steps.organization_id', $organization->getKey())
                ->where('approval_instances.organization_id', $organization->getKey())
                ->where('approval_instances.timetable_version_id', $version->getKey())
                ->where('approval_instance_steps.status', ApprovalInstanceStepStatus::Approved->value)
                ->where('approval_actions.decision', ApprovalDecision::Approve->value)
                ->whereNotNull('approval_instance_steps.signatory_slot')
                ->orderBy('approval_instance_steps.sequence')
                ->orderByDesc('approval_actions.acted_at')
                ->orderByDesc('approval_actions.id')
                ->get();
            $signatories = [];

            foreach ($actions as $action) {
                if (! is_string($action->signatory_slot) || $action->signatory_slot === '') {
                    continue;
                }

                if (isset($signatories[$action->signatory_slot])) {
                    continue;
                }

                $signatories[$action->signatory_slot] = [
                    'name' => is_string($action->signatory_name) ? $action->signatory_name : '',
                    'position' => is_string($action->signatory_position) ? $action->signatory_position : '',
                    ...$this->signatureSnapshot($action),
                ];
            }

            return $signatories;
        });
    }

    /**
     * @return array{signature_checksum: string|null, signature_disk: string|null, signature_path: string|null}
     */
    private function signatureSnapshot(stdClass $action): array
    {
        $hasSignatureMetadata = $action->signature_checksum !== null
            || $action->signature_disk !== null
            || $action->signature_path !== null;

        if (! $hasSignatureMetadata) {
            return [
                'signature_checksum' => null,
                'signature_disk' => null,
                'signature_path' => null,
            ];
        }

        if (! is_string($action->signature_checksum)
            || ! is_string($action->signature_disk)
            || ! is_string($action->signature_path)
            || $action->signature_disk !== SignatoryProfile::SIGNATURE_DISK
            || $action->signature_path === ''
            || strlen($action->signature_checksum) !== 64
            || ! ctype_xdigit($action->signature_checksum)) {
            throw new LogicException('An approved signatory snapshot has invalid private signature metadata.');
        }

        return [
            'signature_checksum' => $action->signature_checksum,
            'signature_disk' => $action->signature_disk,
            'signature_path' => $action->signature_path,
        ];
    }
}
