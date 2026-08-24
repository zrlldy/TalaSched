<?php

namespace App\Approvals;

use stdClass;

class ApprovalInstanceStepRow
{
    /**
     * @param  list<string>  $approverRoleCodes
     */
    public function __construct(
        public int $id,
        public string $status,
        public bool $allowSelfApproval,
        public ?string $approverSelectorType,
        public ?string $requiredPermission,
        public array $approverRoleCodes,
        public int $minimumApprovals,
    ) {}

    public static function from(stdClass $row): self
    {
        $roleCodes = json_decode((string) ($row->approver_role_codes ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $approverRoleCodes = is_array($roleCodes)
            ? array_values(array_filter($roleCodes, fn (mixed $code): bool => is_string($code) && $code !== ''))
            : [];

        return new self(
            id: (int) $row->id,
            status: (string) $row->status,
            allowSelfApproval: (bool) $row->allow_self_approval,
            approverSelectorType: is_string($row->approver_selector_type) ? $row->approver_selector_type : null,
            requiredPermission: is_string($row->required_permission) ? $row->required_permission : null,
            approverRoleCodes: $approverRoleCodes,
            minimumApprovals: (int) $row->minimum_approvals,
        );
    }
}
