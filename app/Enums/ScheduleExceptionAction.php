<?php

namespace App\Enums;

enum ScheduleExceptionAction: string
{
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
    case Replaced = 'replaced';
}
