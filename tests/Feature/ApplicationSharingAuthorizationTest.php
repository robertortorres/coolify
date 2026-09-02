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

test('private application denies other team members', function (string $role) {
    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => $role]);

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    foreach ([
        'view', 'update', 'delete', 'deploy',
        'manageDeployments', 'manageEnvironment', 'uploadBackup',
    ] as $ability) {
        expect($other->can($ability, $this->application))
            ->toBeFalse("Unexpected access: {$role} / {$ability}");
    }
})->with(['member', 'operator', 'admin']);

test('private application remains visible to creator and owning team owner', function () {
    $creator = User::factory()->create();
    $creator->teams()->attach($this->team, ['role' => 'operator']);

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $creator->id,
    ])->save();

    expect($creator->can('view', $this->application))->toBeTrue()
        ->and($this->owner->can('view', $this->application))->toBeTrue();
});

test('custom read access can be granted and revoked', function (string $recipient) {
    $other = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $other->teams()->attach($otherTeam, ['role' => 'operator']);

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    expect($other->can('view', $this->application))->toBeFalse();

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        $recipient => $recipient === 'user_id' ? $other->id : $otherTeam->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    expect($other->can('view', $this->application))->toBeTrue();

    foreach (['update', 'delete', 'deploy', 'manageEnvironment'] as $ability) {
        expect($other->can($ability, $this->application))->toBeFalse();
    }

    $share->delete();

    expect($other->can('view', $this->application))->toBeFalse();
})->with(['user_id', 'team_id']);

test('custom operate grant permits deployment but not administration', function (string $recipient) {
    $other = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $other->teams()->attach($otherTeam, ['role' => 'operator']);

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        $recipient => $recipient === 'user_id' ? $other->id : $otherTeam->id,
        'permission' => 'operate',
        'granted_by' => $this->owner->id,
    ]);

    foreach (['view', 'deploy', 'manageDeployments'] as $ability) {
        expect($other->can($ability, $this->application))
            ->toBeTrue("Expected operate access: {$ability}");
    }

    foreach (['update', 'delete', 'manageEnvironment', 'uploadBackup'] as $ability) {
        expect($other->can($ability, $this->application))
            ->toBeFalse("Unexpected administrative access: {$ability}");
    }

    $share->delete();

    foreach (['view', 'deploy', 'manageDeployments'] as $ability) {
        expect($other->can($ability, $this->application))->toBeFalse();
    }
})->with(['user_id', 'team_id']);

test('private visibility ignores explicit operate grants', function () {
    $other = User::factory()->create();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => 'operate',
        'granted_by' => $this->owner->id,
    ]);

    foreach (['view', 'deploy', 'manageDeployments'] as $ability) {
        expect($other->can($ability, $this->application))->toBeFalse();
    }
});
