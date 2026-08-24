<?php

namespace App\Enums;

enum SubjectOfferingStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
}
