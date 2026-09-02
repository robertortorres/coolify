<?php

use App\Models\Application;
use App\Models\ApplicationShare;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();

    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
    ]);

    InstanceSettings::forceCreate(['id' => 0]);

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

test('application storage defaults to team visibility without inventing a creator', function () {
    $application = $this->application->fresh();

    expect($application->visibility)->toBe('team')
        ->and($application->created_by)->toBeNull();
});

test('sharing storage supports an explicit user or team recipient', function (string $recipient) {
    $target = $recipient === 'user_id'
        ? User::factory()->create()
        : Team::factory()->create();

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        $recipient => $target->id,
        'granted_by' => $this->owner->id,
    ]);

    expect($share->fresh()->permission)->toBe('read')
        ->and($share->application->is($this->application))->toBeTrue()

        ->and($share->grantedBy->is($this->owner))->toBeTrue()
        ->and($share->{$recipient})->toBe($target->id);
})->with(['user_id', 'team_id']);

test('sharing model rejects ambiguous or missing recipients', function (bool $both) {
    $attributes = ['application_id' => $this->application->id];
    if ($both) {
        $attributes['user_id'] = $this->owner->id;
        $attributes['team_id'] = $this->team->id;
    }
    expect(fn () => ApplicationShare::create($attributes))
        ->toThrow(ValidationException::class);
    expect(ApplicationShare::count())->toBe(0);
})->with([false, true]);

test('sharing model rejects unsupported permissions', function () {
    expect(fn () => ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $this->owner->id,
        'permission' => 'admin',
    ]))->toThrow(ValidationException::class);
});

test('sharing storage accepts operate access', function () {
    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $this->owner->id,
        'permission' => 'operate',
    ]);
    expect($share->fresh()->permission)->toBe('operate');
});

test('sharing storage rejects duplicate grants', function (string $recipient) {
    $attributes = [
        'application_id' => $this->application->id,
        $recipient => $recipient === 'user_id'
            ? $this->owner->id : $this->team->id,
    ];
    ApplicationShare::create($attributes);
    expect(fn () => ApplicationShare::create($attributes))
        ->toThrow(QueryException::class);
})->with(['user_id', 'team_id']);
