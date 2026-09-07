<?php

namespace App\Enums;

enum ExcelTemplateStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
