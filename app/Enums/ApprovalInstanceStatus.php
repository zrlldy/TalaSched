<?php

namespace App\Enums;

enum ApprovalInstanceStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ChangesRequested = 'changes_requested';
    case Cancelled = 'cancelled';
}
