<?php

namespace App\Enums;

enum AcademicYearStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
