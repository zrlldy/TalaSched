<?php

namespace App\Enums;

enum TimetableVersionStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Published = 'published';
    case Superseded = 'superseded';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested], true);
    }
}
