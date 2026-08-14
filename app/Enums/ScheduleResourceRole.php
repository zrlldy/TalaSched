<?php

namespace App\Enums;

enum ScheduleResourceRole: string
{
    case Instructor = 'instructor';
    case StudentGroup = 'student_group';
    case Room = 'room';
    case Equipment = 'equipment';
}
