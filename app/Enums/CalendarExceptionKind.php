<?php

namespace App\Enums;

enum CalendarExceptionKind: string
{
    case Holiday = 'holiday';
    case Blocked = 'blocked';
    case Teaching = 'teaching';
}
