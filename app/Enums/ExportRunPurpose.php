<?php

namespace App\Enums;

enum ExportRunPurpose: string
{
    case Preview = 'preview';
    case Export = 'export';
}
