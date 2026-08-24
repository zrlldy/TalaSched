<?php

namespace App\Enums;

enum FacultyEmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
}
