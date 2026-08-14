<?php

namespace App\Enums;

enum AvailabilityKind: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Preferred = 'preferred';
    case Avoid = 'avoid';
}
