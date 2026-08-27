<?php

namespace App\Approvals;

use stdClass;

class ApprovalInstanceRow
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $timetableVersionId,
        public int $approvalWorkflowVersionId,
        public string $status,
        public int $submittedBy,
    ) {}

    public static function from(stdClass $row): self
    {
        return new self(
            id: (int) $row->id,
            organizationId: (int) $row->organization_id,
            timetableVersionId: (int) $row->timetable_version_id,
            approvalWorkflowVersionId: (int) $row->approval_workflow_version_id,
            status: (string) $row->status,
            submittedBy: (int) $row->submitted_by,
        );
    }
}
