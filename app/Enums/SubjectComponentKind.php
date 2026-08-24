<?php

namespace App\Enums;

enum SubjectComponentKind: string
{
    case Lecture = 'lecture';
    case Laboratory = 'laboratory';
    case Seminar = 'seminar';
    case Custom = 'custom';
}
