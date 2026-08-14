<?php

namespace App\Enums;

enum AcademicPeriodKind: string
{
    case Semester = 'semester';
    case Trimester = 'trimester';
    case Quarter = 'quarter';
    case Term = 'term';
    case Summer = 'summer';
    case Custom = 'custom';
}
