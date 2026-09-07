<?php

namespace App\Enums;

enum ExportRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Pending, self::Running => false,
        };
    }
}
