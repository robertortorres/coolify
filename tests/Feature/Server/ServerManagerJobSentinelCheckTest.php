<?php

use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\ServerManagerJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'instance_timezone' => 'UTC',
    ]);

    Carbon::setTestNow(
        Carbon::parse('2025-01-15 12:00:00', 'UTC')
    );

    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('skips the SSH connection check while Sentinel heartbeat is healthy', function () {
    $team = Team::factory()->create();

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => now(),
    ]);

    $server->settings->update([
        'is_sentinel_enabled' => true,
        'sentinel_push_interval_seconds' => 40,
        'server_timezone' => 'UTC',
    ]);

    (new ServerManagerJob)->handle();

    Queue::assertNotPushed(
        ServerConnectionCheckJob::class,
        fn (ServerConnectionCheckJob $job) => $job->server->is($server)
    );
});
