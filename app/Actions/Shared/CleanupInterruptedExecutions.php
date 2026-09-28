<?php

namespace App\Actions\Shared;

use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;

class CleanupInterruptedExecutions
{
    private const INTERRUPTION_MESSAGE = 'Marked as failed during Coolify startup - job was interrupted';

    public function scheduledTasks(): int
    {
        return ScheduledTaskExecution::where('status', 'running')->update([
            'status' => 'failed',
            'message' => self::INTERRUPTION_MESSAGE,
            'finished_at' => now(),
        ]);
    }

    public function databaseBackups(): int
    {
        return ScheduledDatabaseBackupExecution::where('status', 'running')->update([
            'status' => 'failed',
            'message' => self::INTERRUPTION_MESSAGE,
            'finished_at' => now(),
        ]);
    }
}
