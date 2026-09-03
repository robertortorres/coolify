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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
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

test('application creation records the authenticated creator', function () {
    $application = $this->application->fresh();

    expect($application->visibility)->toBe('team')
        ->and($application->created_by)->toBe($this->owner->id);
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

test('instance administrator can manage a private application', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $superadmin = User::factory()->create();
    $superadmin->teams()->attach($rootTeam, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    expect($superadmin->teams()->whereKey($this->team->id)->exists())
        ->toBeFalse();

    foreach (['view', 'update', 'delete', 'deploy', 'manageEnvironment'] as $ability) {
        expect($superadmin->can($ability, $this->application))->toBeTrue();
    }
});

test('private creator loses access after leaving the owning team', function () {
    $creator = User::factory()->create();
    $creator->teams()->attach($this->team, ['role' => 'operator']);

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $creator->id,
    ])->save();

    expect($creator->can('view', $this->application))->toBeTrue();

    $creator->teams()->detach($this->team->id);

    foreach (['view', 'update', 'deploy'] as $ability) {
        expect($creator->can($ability, $this->application))->toBeFalse();
    }

    expect($this->owner->can('view', $this->application))->toBeTrue();
});

test('team share stops granting access after recipient leaves that team', function (string $permission) {
    $recipient = User::factory()->create();
    $recipientTeam = Team::factory()->create();
    $recipient->teams()->attach($recipientTeam, ['role' => 'operator']);

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    ApplicationShare::create([
        'application_id' => $this->application->id,
        'team_id' => $recipientTeam->id,
        'permission' => $permission,
        'granted_by' => $this->owner->id,
    ]);

    expect($recipient->can('view', $this->application))->toBeTrue();

    $recipient->teams()->detach($recipientTeam->id);

    foreach (['view', 'deploy', 'manageDeployments'] as $ability) {
        expect($recipient->can($ability, $this->application))->toBeFalse();
    }
})->with(['read', 'operate']);

test('application creation without authentication leaves creator unset', function () {
    auth()->logout();

    $application = Application::factory()->create([
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);

    expect($application->fresh()->created_by)->toBeNull();
});

test('new application records the operator who actually created it', function () {
    $operator = User::factory()->create();
    $operator->teams()->attach($this->team, ['role' => 'operator']);
    $this->actingAs($operator);

    $application = Application::factory()->create([
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);

    expect($application->fresh()->created_by)->toBe($operator->id);
});

test('editing an application preserves its original creator', function () {
    $operator = User::factory()->create();
    $operator->teams()->attach($this->team, ['role' => 'operator']);
    $this->actingAs($operator);

    $this->application->name = 'Updated application name';
    $this->application->save();

    expect($this->application->fresh()->created_by)->toBe($this->owner->id);
});

test('editing a legacy application does not invent a creator', function () {
    $this->application->forceFill(['created_by' => null])->save();

    $this->application->name = 'Updated legacy application';
    $this->application->save();

    expect($this->application->fresh()->created_by)->toBeNull();
});

test('cloning records the new creator without copying explicit shares', function () {
    $recipient = User::factory()->create();
    ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $recipient->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    $operator = User::factory()->create();
    $operator->teams()->attach($this->team, ['role' => 'operator']);
    $this->actingAs($operator);

    $this->application->refresh();

    $clone = clone_application(
        $this->application,
        $this->application->destination
    );

    expect($clone->fresh()->created_by)->toBe($operator->id)
        ->and($this->application->fresh()->created_by)->toBe($this->owner->id)
        ->and(ApplicationShare::where('application_id', $clone->id)->exists())
        ->toBeFalse()
        ->and(ApplicationShare::where('application_id', $this->application->id)->count())
        ->toBe(1);
});

test('web access hides private applications from other team members', function (
    string $role,
    string $surface
) {
    $this->withoutVite();

    $this->application->forceFill([
        'name' => 'vcc-private-visibility-probe',
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    Application::factory()->create([
        'name' => 'vcc-team-visible-probe',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => $role]);

    Team::query()->update(['show_boarding' => false]);
    Cache::flush();

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);

    $environment = $this->application->environment;
    $parameters = [
        'project_uuid' => $environment->project->uuid,
        'environment_uuid' => $environment->uuid,
    ];

    if ($surface === 'listing') {
        $this->get(route('project.resource.index', $parameters))
            ->assertSuccessful()
            ->assertSee('vcc-team-visible-probe')
            ->assertDontSee('vcc-private-visibility-probe')
            ->assertDontSee($this->application->uuid);
    } else {
        $parameters['application_uuid'] = $this->application->uuid;
        $this->get(route('project.application.configuration', $parameters))
            ->assertNotFound();
    }
})->with([
    'member listing' => ['member', 'listing'],
    'operator listing' => ['operator', 'listing'],
    'member direct URL' => ['member', 'direct'],
    'operator direct URL' => ['operator', 'direct'],
]);

test('API read token cannot expose another members private application', function (
    string $role,
    string $surface
) {
    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $visible = Application::factory()->create([
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => $role]);

    session(['currentTeam' => $this->team]);
    $token = $other->createToken('privacy-read-test', ['read']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $this->withToken($token->plainTextToken);

    $this->getJson('/api/v1/applications/'.$visible->uuid)
        ->assertSuccessful()
        ->assertJsonFragment(['uuid' => $visible->uuid]);

    if ($surface === 'listing') {
        $this->getJson('/api/v1/applications')
            ->assertSuccessful()
            ->assertJsonFragment(['uuid' => $visible->uuid])
            ->assertJsonMissing(['uuid' => $this->application->uuid]);
    } else {
        $this->getJson('/api/v1/applications/'.$this->application->uuid)
            ->assertNotFound();
    }
})->with([
    'member listing' => ['member', 'listing'],
    'operator listing' => ['operator', 'listing'],
    'member direct API' => ['member', 'direct'],
    'operator direct API' => ['operator', 'direct'],
]);
