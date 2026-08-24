<?php

namespace App\Enums;

enum ApprovalInstanceStepStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ChangesRequested = 'changes_requested';
    case Cancelled = 'cancelled';
}
