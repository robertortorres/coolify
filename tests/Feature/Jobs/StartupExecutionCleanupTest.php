<?php

use App\Actions\Shared\CleanupInterruptedExecutions;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Freeze time for consistent testing
    Carbon::setTestNow('2025-01-15 12:00:00');

    // Fake notifications to ensure none are sent
    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('startup cleanup marks stuck scheduled task executions as failed', function () {
    // Create a team for the scheduled task
    $team = Team::factory()->create();

    // Create a scheduled task
    $scheduledTask = ScheduledTask::factory()->create([
        'team_id' => $team->id,
    ]);

    // Create multiple task executions with 'running' status
    $runningExecution1 = ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'running',
        'started_at' => Carbon::now()->subMinutes(10),
    ]);

    $runningExecution2 = ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'running',
        'started_at' => Carbon::now()->subMinutes(5),
    ]);

    // Create a completed execution (should not be affected)
    $completedExecution = ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'success',
        'started_at' => Carbon::now()->subMinutes(15),
        'finished_at' => Carbon::now()->subMinutes(14),
    ]);

    (new CleanupInterruptedExecutions)->scheduledTasks();

    // Refresh models from database
    $runningExecution1->refresh();
    $runningExecution2->refresh();
    $completedExecution->refresh();

    // Assert running executions are now failed
    expect($runningExecution1->status)->toBe('failed')
        ->and($runningExecution1->message)->toBe('Marked as failed during Coolify startup - job was interrupted')
        ->and($runningExecution1->finished_at)->not->toBeNull()
        ->and($runningExecution1->finished_at->toDateTimeString())->toBe('2025-01-15 12:00:00');

    expect($runningExecution2->status)->toBe('failed')
        ->and($runningExecution2->message)->toBe('Marked as failed during Coolify startup - job was interrupted')
        ->and($runningExecution2->finished_at)->not->toBeNull();

    // Assert completed execution is unchanged
    expect($completedExecution->status)->toBe('success')
        ->and($completedExecution->message)->toBeNull();

    // Assert NO notifications were sent
    Notification::assertNothingSent();
});

test('startup cleanup marks stuck database backup executions as failed', function () {
    // Create a team for the scheduled backup
    $team = Team::factory()->create();

    // The cleanup only needs a valid scheduled backup parent. The
    // polymorphic database itself is not accessed by this operation.
    $scheduledBackup = ScheduledDatabaseBackup::create([
        'team_id' => $team->id,
        'database_id' => 1,
        'database_type' => StandalonePostgresql::class,
        'frequency' => '0 0 * * *',
        'enabled' => true,
        'save_s3' => false,
    ]);

    // Create multiple backup executions with 'running' status
    $runningBackup1 = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $scheduledBackup->id,
        'status' => 'running',
        'database_name' => 'test_db',
    ]);

    $runningBackup2 = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $scheduledBackup->id,
        'status' => 'running',
        'database_name' => 'test_db_2',
    ]);

    // Create a successful backup (should not be affected)
    $successfulBackup = ScheduledDatabaseBackupExecution::create([
        'scheduled_database_backup_id' => $scheduledBackup->id,
        'status' => 'success',
        'database_name' => 'test_db_3',
        'finished_at' => Carbon::now()->subMinutes(20),
    ]);

    (new CleanupInterruptedExecutions)->databaseBackups();

    // Refresh models from database
    $runningBackup1->refresh();
    $runningBackup2->refresh();
    $successfulBackup->refresh();

    // Assert running backups are now failed
    expect($runningBackup1->status)->toBe('failed')
        ->and($runningBackup1->message)->toBe('Marked as failed during Coolify startup - job was interrupted')
        ->and($runningBackup1->finished_at)->not->toBeNull()
        ->and($runningBackup1->finished_at->toDateTimeString())->toBe('2025-01-15 12:00:00');

    expect($runningBackup2->status)->toBe('failed')
        ->and($runningBackup2->message)->toBe('Marked as failed during Coolify startup - job was interrupted')
        ->and($runningBackup2->finished_at)->not->toBeNull();

    // Assert successful backup is unchanged
    expect($successfulBackup->status)->toBe('success')
        ->and($successfulBackup->message)->toBeNull();

    // Assert NO notifications were sent
    Notification::assertNothingSent();
});

test('startup cleanup handles cleanup when no stuck executions exist', function () {
    // Create a team
    $team = Team::factory()->create();

    // Create a scheduled task
    $scheduledTask = ScheduledTask::factory()->create([
        'team_id' => $team->id,
    ]);

    // Create only completed executions
    ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'success',
        'started_at' => Carbon::now()->subMinutes(10),
        'finished_at' => Carbon::now()->subMinutes(9),
    ]);

    ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'failed',
        'started_at' => Carbon::now()->subMinutes(20),
        'finished_at' => Carbon::now()->subMinutes(19),
    ]);

    $cleanup = new CleanupInterruptedExecutions;

    expect($cleanup->scheduledTasks())->toBe(0)
        ->and($cleanup->databaseBackups())->toBe(0);

    // Assert all executions remain unchanged
    expect(ScheduledTaskExecution::where('status', 'running')->count())->toBe(0)
        ->and(ScheduledTaskExecution::where('status', 'success')->count())->toBe(1)
        ->and(ScheduledTaskExecution::where('status', 'failed')->count())->toBe(1);

    // Assert NO notifications were sent
    Notification::assertNothingSent();
});

test('cleanup does not send notifications even when team has notification settings', function () {
    // Create a team with notification settings enabled
    $team = Team::factory()->create();

    $team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'smtp_from_address' => 'test@example.com',
    ]);

    // Create a scheduled task
    $scheduledTask = ScheduledTask::factory()->create([
        'team_id' => $team->id,
    ]);

    // Create a running execution
    $runningExecution = ScheduledTaskExecution::create([
        'scheduled_task_id' => $scheduledTask->id,
        'status' => 'running',
        'started_at' => Carbon::now()->subMinutes(5),
    ]);

    (new CleanupInterruptedExecutions)->scheduledTasks();

    // Refresh model
    $runningExecution->refresh();

    // Assert execution is failed
    expect($runningExecution->status)->toBe('failed');

    // Assert NO notifications were sent despite team having notification settings
    Notification::assertNothingSent();
});
