<?php

namespace App\Enums;

enum ResourceType: string
{
    case Faculty = 'faculty';
    case Room = 'room';
    case StudentGroup = 'student_group';
    case Equipment = 'equipment';
}
