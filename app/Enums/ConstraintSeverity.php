<?php

namespace App\Enums;

enum ConstraintSeverity: string
{
    case Hard = 'hard';
    case Soft = 'soft';
}
