<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\GlobalSearch;
use App\Livewire\Project\Shared\EnvironmentVariable\Add;
use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Livewire\Project\Shared\GetLogs;
use App\Livewire\Project\Shared\Logs;
use App\Livewire\Project\Shared\SecretManagerLinks;
use App\Livewire\Project\Shared\Tags;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\ApplicationShare;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

test('global search hides private applications with cold or owner warmed cache', function (
    string $role,
    bool $warmCache
) {
    $this->withoutVite();
    Cache::flush();

    $this->application->forceFill([
        'name' => 'vcc-private-search-probe',
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $visible = Application::factory()->create([
        'name' => 'vcc-team-search-probe',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);

    if ($warmCache) {
        $ownerSearch = Livewire::test(GlobalSearch::class)
            ->call('openSearchModal');

        expect(json_encode($ownerSearch->get('allSearchableItems')))
            ->toContain('vcc-private-search-probe');
    }

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => $role]);
    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Once::flush();

    $search = Livewire::test(GlobalSearch::class)
        ->call('openSearchModal');

    $payload = json_encode($search->get('allSearchableItems'));

    expect($payload)
        ->toContain($visible->uuid)
        ->not->toContain('vcc-private-search-probe')
        ->not->toContain($this->application->uuid);

    $search->set('searchQuery', 'vcc-private-search-probe');

    expect(json_encode($search->get('searchResults')))
        ->not->toContain('vcc-private-search-probe')
        ->not->toContain($this->application->uuid);
})->with([
    'member cold cache' => ['member', false],
    'operator cold cache' => ['operator', false],
    'member owner warmed cache' => ['member', true],
    'operator owner warmed cache' => ['operator', true],
]);

test('global search removes revoked grants on the next search', function (string $recipient) {
    $this->withoutVite();
    Cache::flush();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'operator']);

    $this->application->forceFill([
        'name' => 'vcc-revocation-search-probe',
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        $recipient => $recipient === 'user_id'
            ? $other->id : $this->team->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Once::flush();

    $search = Livewire::test(GlobalSearch::class)
        ->call('openSearchModal')
        ->set('searchQuery', 'vcc-revocation');

    expect(json_encode($search->get('searchResults')))
        ->toContain($this->application->uuid);

    $share->delete();

    $search->set('searchQuery', 'vcc-revocation-search');

    foreach (['allSearchableItems', 'searchResults'] as $property) {
        expect(json_encode($search->get($property)))
            ->not->toContain($this->application->uuid)
            ->not->toContain('vcc-revocation-search-probe');
    }
})->with(['user_id', 'team_id']);

test('global search lifecycle drops revoked application data', function (string $action) {
    $this->withoutVite();
    Cache::flush();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'operator']);

    $this->application->forceFill([
        'name' => 'vcc-lifecycle-search-probe',
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Once::flush();

    $search = Livewire::test(GlobalSearch::class)
        ->call('openSearchModal')
        ->set('searchQuery', 'vcc-lifecycle');

    expect(json_encode($search->get('searchResults')))
        ->toContain($this->application->uuid);

    $share->delete();

    if ($action === 'close') {
        $search->call('closeSearchModal')
            ->assertSet('isModalOpen', false);
    } elseif ($action === 'reopen') {
        $search->call('closeSearchModal')
            ->call('openSearchModal')
            ->assertSet('isModalOpen', true);
    } else {
        $search->call('$refresh');
    }

    foreach (['allSearchableItems', 'searchResults'] as $property) {
        expect(json_encode($search->get($property)))
            ->not->toContain($this->application->uuid)
            ->not->toContain('vcc-lifecycle-search-probe');
    }
})->with(['close', 'reopen', 'refresh']);

test('deployment API hides private applications from other team members', function (
    string $role,
    string $surface
) {
    Process::fake();
    Queue::fake();

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

    $serverId = $this->application->destination->server_id;
    $deployments = [];

    foreach ([$this->application, $visible] as $application) {
        $deployments[] = ApplicationDeploymentQueue::create([
            'deployment_uuid' => (string) Str::uuid(),
            'application_id' => $application->id,
            'server_id' => $serverId,
            'status' => 'queued',
        ]);
    }

    [$privateDeployment, $visibleDeployment] = $deployments;

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => $role]);
    session(['currentTeam' => $this->team]);
    $token = $other->createToken('deployment-privacy-test', ['read']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $this->withToken($token->plainTextToken);

    $this->getJson('/api/v1/deployments/'.$visibleDeployment->deployment_uuid)
        ->assertSuccessful()
        ->assertJsonFragment([
            'deployment_uuid' => $visibleDeployment->deployment_uuid,
        ]);

    if ($surface === 'listing') {
        $this->getJson('/api/v1/deployments')
            ->assertSuccessful()
            ->assertJsonFragment([
                'deployment_uuid' => $visibleDeployment->deployment_uuid,
            ])
            ->assertJsonMissing([
                'deployment_uuid' => $privateDeployment->deployment_uuid,
            ]);
    } else {
        $this->getJson('/api/v1/deployments/'.$privateDeployment->deployment_uuid)
            ->assertNotFound();
    }

    Process::assertNothingRan();
    Queue::assertNothingPushed();
})->with([
    'member listing' => ['member', 'listing'],
    'operator listing' => ['operator', 'listing'],
    'member detail' => ['member', 'detail'],
    'operator detail' => ['operator', 'detail'],
]);

test('private application logs are denied before running remote commands', function (string $role) {
    Process::fake();
    Queue::fake();

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
    $token = $other->createToken('private-logs-test', ['read']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $this->withToken($token->plainTextToken);

    $this->getJson('/api/v1/applications/'.$visible->uuid)
        ->assertSuccessful()
        ->assertJsonFragment(['uuid' => $visible->uuid]);

    $response = $this->getJson(
        '/api/v1/applications/'.$this->application->uuid.'/logs'
    );

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $response->assertNotFound()
        ->assertExactJson(['message' => 'Application not found.']);
})->with(['member', 'operator', 'admin']);

test('authorized application logs return simulated container output', function () {
    Storage::fake('ssh-keys');
    Queue::fake();
    config(['constants.ssh.mux_enabled' => false]);

    Process::fake([
        '*docker ps -a*' => Process::result(
            output: json_encode([
                'ID' => 'vcc-logs-container',
                'Names' => 'vcc-logs-container',
                'Labels' => 'coolify.applicationId='.$this->application->id,
            ]),
        ),
        '*docker inspect*' => Process::result(
            output: '{"State":{"Status":"running"}}',
        ),
        '*docker logs*' => Process::result(
            output: 'vcc-authorized-log-output',
        ),
        '*' => Process::result(
            errorOutput: 'Unexpected command in logs test',
            exitCode: 1,
        ),
    ]);

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $key = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $server = $this->application->destination->server;
    $server->forceFill(['private_key_id' => $key->id])->save();
    Server::flushIdentityMap();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $token = $this->owner->createToken('authorized-logs-test', ['read']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/applications/'.$this->application->uuid.'/logs')
        ->assertOk()
        ->assertExactJson(['logs' => 'vcc-authorized-log-output']);

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker ps -a'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker inspect'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker logs'));
    Process::assertRanTimes(fn ($process) => true, 3);
    Queue::assertNothingPushed();
});

test('deployment cancellation respects application sharing permissions', function (string $visibility) {
    Process::fake();
    Queue::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => $visibility,
        'created_by' => $this->owner->id,
    ])->save();

    if ($visibility === 'custom') {
        ApplicationShare::create([
            'application_id' => $this->application->id,
            'user_id' => $other->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
        ]);
    }

    expect($other->can('view', $this->application))
        ->toBe($visibility === 'custom');
    expect($other->can('manageDeployments', $this->application))
        ->toBeFalse();

    $deployment = ApplicationDeploymentQueue::create([
        'deployment_uuid' => (string) Str::uuid(),
        'application_id' => $this->application->id,
        'server_id' => $this->application->destination->server_id,
        'status' => 'queued',
    ]);

    session(['currentTeam' => $this->team]);
    $token = $other->createToken('restricted-cancellation-test', ['*']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/deployments/'.$deployment->deployment_uuid.'/cancel');

    expect($deployment->fresh()->status)->toBe('queued');
    Process::assertNothingRan();
    Queue::assertNothingPushed();

    if ($visibility === 'private') {
        $response->assertNotFound()
            ->assertExactJson(['message' => 'Deployment not found.']);
    } else {
        $response->assertForbidden()->assertExactJson([
            'message' => 'You do not have permission to cancel this deployment.',
        ]);
    }
})->with(['private', 'custom']);

test('operate sharing permits deployment cancellation through API', function (string $recipient) {
    Process::fake();
    Queue::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    expect($other->can('manageDeployments', $this->application))->toBeFalse();

    ApplicationShare::create([
        'application_id' => $this->application->id,
        $recipient => $recipient === 'user_id' ? $other->id : $this->team->id,
        'permission' => 'operate',
        'granted_by' => $this->owner->id,
    ]);

    expect($other->can('manageDeployments', $this->application))->toBeTrue();

    // An inaccessible build server avoids remote cleanup in this policy test.
    $foreignTeam = Team::factory()->create();
    $buildServer = Server::factory()->create(['team_id' => $foreignTeam->id]);

    $deployment = ApplicationDeploymentQueue::create([
        'deployment_uuid' => (string) Str::uuid(),
        'application_id' => $this->application->id,
        'server_id' => $this->application->destination->server_id,
        'build_server_id' => $buildServer->id,
        'status' => 'queued',
    ]);

    session(['currentTeam' => $this->team]);
    $token = $other->createToken('operate-cancellation-test', ['*']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/deployments/'.$deployment->deployment_uuid.'/cancel')
        ->assertOk()
        ->assertExactJson([
            'message' => 'Deployment cancelled successfully.',
            'deployment_uuid' => $deployment->deployment_uuid,
            'status' => 'cancelled-by-user',
        ]);

    expect($deployment->fresh()->status)->toBe('cancelled-by-user');
    Process::assertNothingRan();
    Queue::assertNothingPushed();
})->with(['user_id', 'team_id']);

test('unauthorized deployment cannot change docker image preview', function (string $visibility, bool $existing) {
    Process::fake();
    Queue::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => $visibility,
        'created_by' => $this->owner->id,
        'build_pack' => 'dockerimage',
    ])->save();

    if ($visibility === 'custom') {
        ApplicationShare::create([
            'application_id' => $this->application->id,
            'user_id' => $other->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
        ]);
    }

    expect($other->can('deploy', $this->application))->toBeFalse();

    $preview = $existing ? ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => '',
        'docker_registry_image_tag' => 'original-tag',
    ]) : null;

    session(['currentTeam' => $this->team]);
    $token = $other->createToken('preview-authorization-test', ['*']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/deploy', [
            'uuid' => $this->application->uuid,
            'pull_request_id' => 42,
            'docker_tag' => 'unauthorized-tag',
        ]);

    if ($existing) {
        expect($preview->fresh()->docker_registry_image_tag)->toBe('original-tag');
    }

    expect($this->application->previews()->count())->toBe($existing ? 1 : 0);
    expect(ApplicationDeploymentQueue::count())->toBe(0);
    Process::assertNothingRan();
    Queue::assertNothingPushed();

    if ($visibility === 'private') {
        $response->assertNotFound()
            ->assertExactJson(['message' => 'No resources found.']);
    } else {
        $response->assertOk()->assertExactJson([
            'deployments' => [[
                'message' => 'Unauthorized to deploy this application.',
                'resource_uuid' => $this->application->uuid,
            ]],
        ]);
    }
})->with(['private', 'custom'])->with([true, false]);

test('tag deployment hides inaccessible private applications', function () {
    Process::fake();
    Queue::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    expect($other->can('view', $this->application))->toBeFalse();
    expect($other->can('deploy', $this->application))->toBeFalse();

    $tag = Tag::create([
        'name' => 'vcc-private-deploy-probe',
        'team_id' => $this->team->id,
    ]);

    session(['currentTeam' => $this->team]);
    $token = $other->createToken('private-tag-deployment-test', ['*']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $this->withToken($token->plainTextToken);

    $empty = $this->postJson('/api/v1/deploy', ['tag' => $tag->name]);
    $empty->assertOk()->assertExactJson([
        'message' => ["No resources found for tag {$tag->name}."],
    ]);

    $this->application->tags()->attach($tag->id);

    $response = $this->postJson('/api/v1/deploy', ['tag' => $tag->name]);

    expect(ApplicationDeploymentQueue::count())->toBe(0);
    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $response->assertStatus($empty->status())
        ->assertExactJson($empty->json());
});

test('tag deployment queues only applications with operate access', function () {
    Process::fake();
    Queue::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $tag = Tag::create([
        'name' => 'vcc-mixed-deployment',
        'team_id' => $this->team->id,
    ]);

    $apps = [];
    foreach (['private', 'read', 'operate'] as $access) {
        $app = Application::factory()->create([
            'name' => 'vcc-mixed-'.$access,
            'environment_id' => $this->application->environment_id,
            'destination_id' => $this->application->destination_id,
            'destination_type' => $this->application->destination_type,
            'build_pack' => 'dockerimage',
            'docker_registry_image_name' => 'ghcr.io/coollabsio/example',
            'docker_registry_image_tag' => 'latest',
        ]);

        $app->forceFill([
            'visibility' => $access === 'private' ? 'private' : 'custom',
            'created_by' => $this->owner->id,
        ])->save();
        $app->tags()->attach($tag->id);

        if ($access !== 'private') {
            ApplicationShare::create([
                'application_id' => $app->id,
                'user_id' => $other->id,
                'permission' => $access,
                'granted_by' => $this->owner->id,
            ]);
        }

        expect($other->can('deploy', $app))->toBe($access === 'operate');
        $apps[$access] = $app;
    }

    session(['currentTeam' => $this->team]);
    $token = $other->createToken('mixed-tag-deployment-test', ['*']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/deploy', ['tag' => $tag->name]);

    $response->assertOk()
        ->assertJsonCount(1, 'details')
        ->assertJsonPath('details.0.resource_uuid', $apps['operate']->uuid);

    $deployment = ApplicationDeploymentQueue::sole();
    expect((int) $deployment->application_id)->toBe($apps['operate']->id);
    $response->assertJsonPath('details.0.deployment_uuid', $deployment->deployment_uuid);

    expect($response->json('message'))
        ->toContain('Unauthorized to deploy this application.');
    expect($response->getContent())
        ->not->toContain($apps['private']->uuid)
        ->not->toContain($apps['private']->name);

    Queue::assertPushed(
        ApplicationDeploymentJob::class,
        fn ($job) => $job->application_deployment_queue_id === $deployment->id
    );
    Process::assertNothingRan();
});

test('application tag endpoints hide private applications', function (string $action) {
    Process::fake();
    Queue::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $tag = Tag::create([
        'name' => 'vcc-existing-private-tag',
        'team_id' => $this->team->id,
    ]);
    $this->application->tags()->attach($tag->id);
    $tagCount = Tag::count();

    expect($other->can('view', $this->application))->toBeFalse();
    expect($other->can('update', $this->application))->toBeFalse();

    session(['currentTeam' => $this->team]);
    $token = $other->createToken('private-tag-endpoints-test', ['*']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $this->withToken($token->plainTextToken);
    $url = '/api/v1/applications/'.$this->application->uuid.'/tags';

    $response = match ($action) {
        'list' => $this->getJson($url),
        'create' => $this->postJson($url, [
            'tag_name' => 'vcc-forbidden-new-tag',
        ]),
        'delete' => $this->deleteJson($url.'/'.$tag->uuid),
    };

    expect(Tag::count())->toBe($tagCount);
    expect(Tag::whereKey($tag->id)->exists())->toBeTrue();
    expect($this->application->tags()->pluck('tags.id')->all())
        ->toBe([$tag->id]);

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $response->assertNotFound()
        ->assertExactJson(['message' => 'Application not found.']);
})->with(['list', 'create', 'delete']);

test('tags component refuses to mount an inaccessible private application', function () {
    $this->withoutVite();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $tag = Tag::create([
        'name' => 'vcc-private-component-tag',
        'team_id' => $this->team->id,
    ]);
    $this->application->tags()->attach($tag->id);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeFalse();

    Livewire::test(
        Tags::class,
        ['resource' => $this->application]
    )->assertNotFound();
});

test('tags component rejects requests after access revocation', function (string $action) {
    $this->withoutVite();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    $component = Livewire::test(
        Tags::class,
        ['resource' => $this->application]
    )->assertOk();

    $share->delete();

    expect($other->can('view', $this->application))->toBeFalse();

    $component->call($action)->assertNotFound();
})->with(['loadTags', 'refresh', '$refresh']);

test('tags component cannot attach a tag from another team', function () {
    $this->withoutVite();

    $foreignTeam = Team::factory()->create();
    $tag = Tag::create([
        'name' => 'vcc-foreign-team-tag',
        'team_id' => $foreignTeam->id,
    ]);

    expect($this->owner->can('update', $this->application))->toBeTrue();

    $component = Livewire::test(
        Tags::class,
        ['resource' => $this->application]
    )->assertOk();

    $component->call('addTag', (string) $tag->id, $tag->name);

    expect($this->application->tags()->count())->toBe(0);
    expect(Tag::findOrFail($tag->id)->team_id)
        ->toBe($foreignTeam->id);

    $component->assertNotDispatched('success')
        ->assertDispatched('error');
});

test('tags component attaches an existing tag from its own team', function () {
    $this->withoutVite();

    $tag = Tag::create([
        'name' => 'vcc-own-team-tag',
        'team_id' => $this->team->id,
    ]);

    expect($this->owner->can('update', $this->application))->toBeTrue();

    Livewire::test(
        Tags::class,
        ['resource' => $this->application]
    )
        ->assertOk()
        ->call('addTag', (string) $tag->id, $tag->name)
        ->assertNotDispatched('error')
        ->assertDispatched('success');

    expect($this->application->tags()->pluck('tags.id')->all())
        ->toBe([$tag->id]);
});

test('get logs component refuses an inaccessible private application', function () {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $server = $this->application->destination->server;

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeFalse();
    expect($other->teams()->whereKey($server->team_id)->exists())->toBeTrue();

    $component = Livewire::test(
        GetLogs::class,
        [
            'resource' => $this->application,
            'server' => $server,
            'container' => 'vcc-private-log-container',
        ]
    );

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $component->assertNotFound();
});

test('get logs component denies actions after sharing revocation', function (string $action) {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeTrue();

    $component = Livewire::test(
        GetLogs::class,
        [
            'resource' => $this->application,
            'server' => $this->application->destination->server,
            'container' => 'vcc-revoked-log-container',
        ]
    )->assertOk();

    $share->delete();

    expect($other->can('view', $this->application))->toBeFalse();

    $component->call($action)->assertNotFound();

    Process::assertNothingRan();
    Queue::assertNothingPushed();
})->with(['getLogs', 'downloadAllLogs', 'copyLogs', '$refresh']);

test('get logs shared access cannot persist timestamp settings', function (string $permission, string $action) {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $this->application->settings->forceFill([
        'is_include_timestamps' => false,
    ])->save();

    ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => $permission,
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeTrue();
    expect($other->can('update', $this->application))->toBeFalse();

    $component = Livewire::test(
        GetLogs::class,
        [
            'resource' => $this->application,
            'server' => $this->application->destination->server,
            'container' => 'vcc-timestamps-container',
        ]
    )->assertOk();

    if ($action === 'instantSave') {
        $component->set('showTimeStamps', true);
    }

    $component->call($action);

    expect((bool) $this->application->settings()->firstOrFail()->is_include_timestamps)
        ->toBeFalse();

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    if ($action === 'instantSave') {
        $component->assertForbidden();
    } else {
        $component->assertSet('showTimeStamps', false)
            ->assertDispatched('error');
    }
})->with(['read', 'operate'])->with(['instantSave', 'toggleTimestamps']);

test('get logs owner can persist timestamp settings', function () {
    $this->withoutVite();
    Process::fake();

    $this->application->settings->forceFill([
        'is_include_timestamps' => false,
    ])->save();

    expect($this->owner->can('update', $this->application))->toBeTrue();

    Livewire::test(
        GetLogs::class,
        [
            'resource' => $this->application,
            'server' => $this->application->destination->server,
            'container' => 'vcc-owner-timestamps',
        ]
    )
        ->assertOk()
        ->set('showTimeStamps', true)
        ->call('instantSave')
        ->assertOk()
        ->assertNotDispatched('error');

    expect((bool) $this->application->settings()->firstOrFail()->is_include_timestamps)
        ->toBeTrue();

    Process::assertNothingRan();
});

test('application logs page hides private applications', function () {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    Team::query()->update(['show_boarding' => false]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeFalse();

    $environment = $this->application->environment;
    $response = $this->get(route('project.application.logs', [
        'project_uuid' => $environment->project->uuid,
        'environment_uuid' => $environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $response->assertNotFound();
});

test('get logs shared access reads and downloads simulated output', function (string $permission) {
    $this->withoutVite();
    Storage::fake('ssh-keys');
    Queue::fake();
    config(['constants.ssh.mux_enabled' => false]);

    $key = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $server = $this->application->destination->server;
    $server->forceFill(['private_key_id' => $key->id])->save();
    $server->settings->forceFill([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ])->save();
    Server::flushIdentityMap();
    $server = Server::with('settings')->findOrFail($server->id);

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();
    $this->application->settings->forceFill([
        'is_include_timestamps' => false,
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);
    ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => $permission,
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeTrue();
    expect($other->can('update', $this->application))->toBeFalse();

    Process::shouldReceive('timeout')
        ->twice()->andReturnSelf();
    Process::shouldReceive('run')
        ->twice()
        ->andReturnUsing(function (string $command, ?callable $callback = null) {
            expect($command)->toContain('docker logs')
                ->toContain('vcc-authorized-container');

            if ($callback) {
                $callback('out', 'vcc-simulated-log-output');
            }

            return new FakeProcessResult(command: $command);
        });

    Livewire::test(
        GetLogs::class,
        [
            'resource' => $this->application,
            'server' => $server,
            'container' => 'vcc-authorized-container',
        ]
    )
        ->assertOk()
        ->call('getLogs', true)
        ->assertSet('outputs', 'vcc-simulated-log-output')
        ->call('downloadAllLogs')
        ->assertReturned('vcc-simulated-log-output');

    Queue::assertNothingPushed();
})->with(['read', 'operate']);

test('logs parent rechecks application access after revocation', function (string $action) {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    Team::query()->update(['show_boarding' => false]);
    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    $environment = $this->application->environment;
    $this->get(route('project.application.logs', [
        'project_uuid' => $environment->project->uuid,
        'environment_uuid' => $environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]))->assertOk();

    $component = new Logs;
    $component->resource = $this->application;
    $component->servers = collect([$this->application->destination->server]);

    $share->delete();
    expect($other->can('view', $this->application))->toBeFalse();

    $status = null;
    try {
        $component->{$action}();
    } catch (HttpExceptionInterface $exception) {
        $status = $exception->getStatusCode();
    }

    expect($status)->toBe(404);
    expect($component->containersLoaded)->toBeFalse();
    expect($component->serverContainers)->toBe([]);

    Process::assertNothingRan();
    Queue::assertNothingPushed();
})->with(['hydrate', 'loadAllContainers', 'render']);

test('environment API hides private applications regardless of token sensitivity', function (bool $sensitive) {
    Process::fake();
    Queue::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    EnvironmentVariable::create([
        'key' => 'VCC_PRIVATE_PROBE',
        'value' => 'vcc-fictional-secret-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
        'is_shown_once' => false,
    ]);

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    expect($other->can('view', $this->application))->toBeFalse();

    session(['currentTeam' => $this->team]);
    $abilities = $sensitive ? ['read', 'read:sensitive'] : ['read'];
    $token = $other->createToken('private-env-test', $abilities);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $response = $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/applications/'.$this->application->uuid.'/envs');

    expect($response->getContent())
        ->not->toContain('VCC_PRIVATE_PROBE')
        ->not->toContain('vcc-fictional-secret-value');

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $response->assertNotFound()
        ->assertExactJson(['message' => 'Application not found']);
})->with([false, true]);

test('shared application access cannot mutate environment variables', function (string $permission, string $action) {
    Process::fake();
    Queue::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $env = EnvironmentVariable::create([
        'key' => 'VCC_EXISTING',
        'value' => 'original-fictional-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
        'is_shown_once' => false,
    ]);
    $before = EnvironmentVariable::query()
        ->orderBy('id')->get()->map->getRawOriginal()->all();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);
    ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => $permission,
        'granted_by' => $this->owner->id,
    ]);

    expect($other->can('view', $this->application))->toBeTrue();
    expect($other->can('manageEnvironment', $this->application))->toBeFalse();

    session(['currentTeam' => $this->team]);
    $token = $other->createToken('shared-env-write-test', ['*']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $this->withToken($token->plainTextToken);
    $url = '/api/v1/applications/'.$this->application->uuid.'/envs';
    $change = ['key' => 'VCC_EXISTING', 'value' => 'forbidden-change'];
    $new = ['key' => 'VCC_NEW', 'value' => 'forbidden-new-value'];

    $response = match ($action) {
        'create' => $this->postJson($url, $new),
        'update' => $this->patchJson($url, $change),
        'bulk' => $this->patchJson($url.'/bulk', ['data' => [$change, $new]]),
        'delete' => $this->deleteJson($url.'/'.$env->uuid),
    };

    $after = EnvironmentVariable::query()
        ->orderBy('id')->get()->map->getRawOriginal()->all();
    expect($after)->toBe($before);

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $response->assertForbidden();
})->with(['read', 'operate'])->with(['create', 'update', 'bulk', 'delete']);

test('environment Show rejects direct syncData writes for shared access', function (string $permission) {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $env = EnvironmentVariable::create([
        'key' => 'VCC_DIRECT_SYNC',
        'value' => 'original-fictional-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
        'is_shown_once' => false,
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
        'is_required' => false,
    ]);

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);
    ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => $permission,
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeTrue();
    expect($other->can('manageEnvironment', $this->application))->toBeFalse();

    $component = Livewire::test(
        Show::class,
        ['env' => $env, 'type' => 'application']
    )->assertOk();

    $component->set('value', 'forbidden-fictional-value')
        ->call('syncData', true);

    expect($env->fresh()->value)->toBe('original-fictional-value');

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $component->assertForbidden();
})->with(['read', 'operate']);

test('environment variable policy respects application sharing', function (string $access, string $ability) {
    $this->application->forceFill([
        'visibility' => $access === 'private' ? 'private' : 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $env = EnvironmentVariable::create([
        'key' => 'VCC_POLICY_PROBE',
        'value' => 'fictional-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
    ]);

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    if ($access !== 'private') {
        ApplicationShare::create([
            'application_id' => $this->application->id,
            'user_id' => $other->id,
            'permission' => $access,
            'granted_by' => $this->owner->id,
        ]);
    }

    $expected = $ability === 'view' && $access !== 'private';

    expect($other->can($ability, $env))->toBe($expected);
})->with(['private', 'read', 'operate'])
    ->with(['view', 'update', 'delete', 'manageEnvironment']);

test('environment Show owner can persist through syncData', function () {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $env = EnvironmentVariable::create([
        'key' => 'VCC_OWNER_SYNC',
        'value' => 'original-fictional-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
        'is_shown_once' => false,
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
        'is_required' => false,
    ]);

    foreach (['view', 'update', 'delete', 'manageEnvironment'] as $ability) {
        expect($this->owner->can($ability, $env))->toBeTrue();
    }

    Livewire::test(
        Show::class,
        ['env' => $env, 'type' => 'application']
    )
        ->assertOk()
        ->set('value', 'updated-fictional-value')
        ->call('syncData', true)
        ->assertOk();

    expect($env->fresh()->value)->toBe('updated-fictional-value');

    Process::assertNothingRan();
    Queue::assertNothingPushed();
});

test('environment Show rejects reads after sharing revocation', function (string $action) {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $env = EnvironmentVariable::create([
        'key' => 'VCC_REVOKED_ENV',
        'value' => 'fictional-revocation-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
        'is_shown_once' => false,
    ]);

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $env))->toBeTrue();

    $component = Livewire::test(
        Show::class,
        ['env' => $env, 'type' => 'application']
    )->assertOk();

    $share->delete();

    expect($other->can('view', $env))->toBeFalse();

    $component->call($action);

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $component->assertNotFound();
})->with(['loadValues', 'copyValue', 'syncData', 'refresh', '$refresh']);

test('environment Show refuses an inaccessible private application', function () {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $env = EnvironmentVariable::create([
        'key' => 'VCC_PRIVATE_ENV_COMPONENT',
        'value' => 'fictional-private-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
        'is_shown_once' => false,
    ]);

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $env))->toBeFalse();

    $component = Livewire::test(
        Show::class,
        ['env' => $env, 'type' => 'application']
    );

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $component->assertNotFound();
});

test('environment All refuses an inaccessible private application', function () {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeFalse();

    $component = Livewire::test(
        All::class,
        ['resource' => $this->application]
    );

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $component->assertNotFound();
});

test('environment All rejects requests after sharing revocation', function (string $action) {
    $this->withoutVite();
    Process::fake();
    Queue::fake();

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeTrue();

    $component = Livewire::test(
        All::class,
        ['resource' => $this->application]
    )->assertOk();

    $share->delete();

    expect($other->can('view', $this->application))->toBeFalse();

    $component->call($action);

    Process::assertNothingRan();
    Queue::assertNothingPushed();

    $component->assertNotFound();
})->with([
    'loadEnvironmentVariables',
    'getDevView',
    'switch',
    'refreshEnvs',
    '$refresh',
]);

test('environment Add refuses an inaccessible private application', function () {
    $this->withoutVite();
    Process::fake();
    Queue::fake();
    Http::fake();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeFalse();

    $component = Livewire::test(
        Add::class,
        ['resource' => $this->application]
    );

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    $component->assertNotFound();
});

test('environment Add denies shared access without environment management', function (string $permission) {
    $this->withoutVite();
    Process::fake();
    Queue::fake();
    Http::fake();

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => $permission,
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeTrue();
    expect($other->can('manageEnvironment', $this->application))->toBeFalse();

    $component = Livewire::test(
        Add::class,
        ['resource' => $this->application]
    );

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    $component->assertForbidden();
})->with(['read', 'operate']);

test('environment Add allows the application owner', function () {
    $this->withoutVite();
    Process::fake();
    Queue::fake();
    Http::fake();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    expect($this->owner->can('manageEnvironment', $this->application))->toBeTrue();

    Livewire::test(
        Add::class,
        ['resource' => $this->application]
    )
        ->assertOk()
        ->set('key', 'VCC_OWNER_ADD')
        ->set('value', 'fictional-owner-value')
        ->call('submit')
        ->assertOk()
        ->assertDispatched('saveKey');

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('environment Add shared flag cannot bypass application access', function () {
    $this->withoutVite();
    Process::fake();
    Queue::fake();
    Http::fake();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeFalse();

    Livewire::test(
        Add::class,
        ['resource' => $this->application, 'shared' => true]
    )->assertNotFound();

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('environment Add supports shared variables without an application', function () {
    $this->withoutVite();
    Process::fake();
    Queue::fake();
    Http::fake();

    Livewire::test(
        Add::class,
        ['shared' => true]
    )
        ->assertOk()
        ->set('key', 'VCC_SHARED_FORM')
        ->set('value', 'fictional-shared-value')
        ->call('submit')
        ->assertOk()
        ->assertDispatched('saveKey');

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('environment Add rechecks permissions after visibility changes', function (
    bool $retainRead,
    string $action
) {
    $this->withoutVite();
    Process::fake();
    Queue::fake();
    Http::fake();

    $this->application->forceFill([
        'visibility' => 'team',
        'created_by' => $this->owner->id,
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('manageEnvironment', $this->application))->toBeTrue();

    $component = Livewire::test(
        Add::class,
        ['resource' => $this->application]
    )
        ->assertOk()
        ->set('key', 'VCC_PERMISSION_CHANGE')
        ->set('value', 'fictional-value');

    $this->application->forceFill([
        'visibility' => $retainRead ? 'custom' : 'private',
    ])->save();

    if ($retainRead) {
        ApplicationShare::create([
            'application_id' => $this->application->id,
            'user_id' => $other->id,
            'permission' => 'read',
            'granted_by' => $this->owner->id,
        ]);
    }

    expect($other->can('view', $this->application))->toBe($retainRead);
    expect($other->can('manageEnvironment', $this->application))->toBeFalse();

    $component->call($action)
        ->assertStatus($retainRead ? 403 : 404)
        ->assertNotDispatched('saveKey');

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([false, true])
    ->with(['submit', 'fetchSecretManagerKeys', '$refresh']);

test('secret manager component refuses an inaccessible private application', function () {
    $this->withoutVite();
    Process::fake();
    Queue::fake();
    Http::fake();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeFalse();

    $component = Livewire::test(
        SecretManagerLinks::class,
        ['resource' => $this->application]
    );

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    $component->assertNotFound();
});

test('secret manager component rejects requests after sharing revocation', function (string $action) {
    $this->withoutVite();
    Process::fake();
    Queue::fake();
    Http::fake();

    $this->application->forceFill([
        'visibility' => 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $token = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'VCC fictional source',
        'token' => 'dp.st.fictional-test-token',
        'capabilities' => ['secrets'],
    ]);

    $link = $this->application->secretManagerLink()->create([
        'integration_token_id' => $token->id,
    ]);
    $before = $link->fresh()->getRawOriginal();
    $envCount = $this->application->environment_variables()->count();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $other->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team->fresh()]);
    Cache::flush();
    Once::flush();

    expect($other->can('view', $this->application))->toBeTrue();

    $component = Livewire::test(
        SecretManagerLinks::class,
        ['resource' => $this->application]
    )->assertOk();

    $share->delete();

    expect($other->can('view', $this->application))->toBeFalse();

    $component->call($action)->assertNotFound();

    expect($link->fresh())->not->toBeNull();
    expect($link->fresh()->getRawOriginal())->toBe($before);
    expect($this->application->environment_variables()->count())->toBe($envCount);

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['loadKeys', 'importAll', 'removeSource', 'saveSettings', '$refresh']);

test('secret manager API respects application sharing permissions', function (string $access) {
    Process::fake();
    Queue::fake();
    Http::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $this->application->forceFill([
        'visibility' => $access === 'private' ? 'private' : 'custom',
        'created_by' => $this->owner->id,
    ])->save();

    $source = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'VCC fictional API source',
        'token' => 'dp.sa.fictional-test-token',
        'capabilities' => ['secrets'],
    ]);

    $link = $this->application->secretManagerLink()->create([
        'integration_token_id' => $source->id,
        'settings' => ['project' => 'original-project', 'config' => 'original-config'],
    ]);
    $before = $link->fresh()->getRawOriginal();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    if ($access !== 'private') {
        ApplicationShare::create([
            'application_id' => $this->application->id,
            'user_id' => $other->id,
            'permission' => $access,
            'granted_by' => $this->owner->id,
        ]);
    }

    expect($other->can('view', $this->application))->toBe($access !== 'private');
    expect($other->can('update', $this->application))->toBeFalse();

    session(['currentTeam' => $this->team]);
    $token = $other->createToken('restricted-secret-manager-test', ['*']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $response = $this->withToken($token->plainTextToken)
        ->patchJson('/api/v1/applications/'.$this->application->uuid.'/secret-manager', [
            'integration_token_uuid' => $source->uuid,
            'settings' => ['project' => 'forbidden-project', 'config' => 'forbidden-config'],
        ]);

    expect($link->fresh())->not->toBeNull();
    expect($link->fresh()->getRawOriginal())->toBe($before);

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    if ($access === 'private') {
        $response->assertNotFound()
            ->assertExactJson(['message' => 'Application not found.']);
    } else {
        $response->assertForbidden();
    }
})->with(['private', 'read', 'operate']);

test('environment write endpoints hide inaccessible private applications', function (string $action) {
    Process::fake();
    Queue::fake();
    Http::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $env = EnvironmentVariable::create([
        'key' => 'VCC_PRIVATE_WRITE',
        'value' => 'original-fictional-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
    ]);

    $before = EnvironmentVariable::query()
        ->orderBy('id')->get()->map->getRawOriginal()->all();

    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'admin']);

    expect($other->can('view', $this->application))->toBeFalse();
    expect($other->can('manageEnvironment', $this->application))->toBeFalse();

    session(['currentTeam' => $this->team]);
    $token = $other->createToken('private-env-write-test', ['*']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $this->withToken($token->plainTextToken);
    $url = '/api/v1/applications/'.$this->application->uuid.'/envs';

    $response = match ($action) {
        'create' => $this->postJson($url, [
            'key' => 'VCC_FORBIDDEN_NEW',
            'value' => 'forbidden-fictional-value',
        ]),
        'update' => $this->patchJson($url, [
            'key' => $env->key,
            'value' => 'forbidden-fictional-value',
        ]),
        'bulk' => $this->patchJson($url.'/bulk', [
            'data' => [
                ['key' => $env->key, 'value' => 'forbidden-fictional-value'],
                ['key' => 'VCC_FORBIDDEN_NEW', 'value' => 'forbidden-fictional-value'],
            ],
        ]),
        'delete' => $this->deleteJson($url.'/'.$env->uuid),
    };

    expect(EnvironmentVariable::query()
        ->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before);

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    $response->assertNotFound()->assertExactJson([
        'message' => $action === 'delete'
            ? 'Application not found.'
            : 'Application not found',
    ]);
})->with(['create', 'update', 'bulk', 'delete']);

test('private application owner can mutate environment variables through API', function (string $action) {
    Process::fake();
    Queue::fake();
    Http::fake();

    InstanceSettings::findOrFail(0)->forceFill([
        'is_api_enabled' => true,
        'allowed_ips' => '127.0.0.1',
    ])->save();

    $this->application->forceFill([
        'visibility' => 'private',
        'created_by' => $this->owner->id,
    ])->save();

    $env = EnvironmentVariable::create([
        'key' => 'VCC_OWNER_EXISTING',
        'value' => 'original-fictional-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
    ]);

    expect($this->owner->can('view', $this->application))->toBeTrue();
    expect($this->owner->can('manageEnvironment', $this->application))->toBeTrue();

    session(['currentTeam' => $this->team]);
    $token = $this->owner->createToken('owner-env-write-test', ['*']);

    auth()->logout();
    auth()->forgetGuards();
    Cache::flush();
    Once::flush();

    $this->withToken($token->plainTextToken);
    $url = '/api/v1/applications/'.$this->application->uuid.'/envs';

    $response = match ($action) {
        'create' => $this->postJson($url, [
            'key' => 'VCC_OWNER_NEW',
            'value' => 'new-fictional-value',
        ]),
        'update' => $this->patchJson($url, [
            'key' => $env->key,
            'value' => 'updated-fictional-value',
        ]),
        'bulk' => $this->patchJson($url.'/bulk', [
            'data' => [
                ['key' => $env->key, 'value' => 'updated-fictional-value'],
                ['key' => 'VCC_OWNER_NEW', 'value' => 'new-fictional-value'],
            ],
        ]),
        'delete' => $this->deleteJson($url.'/'.$env->uuid),
    };

    $response->assertSuccessful();

    if ($action === 'delete') {
        expect($env->fresh())->toBeNull();
    } else {
        expect($env->fresh()->value)->toBe(
            $action === 'create'
                ? 'original-fictional-value'
                : 'updated-fictional-value'
        );
    }

    if (in_array($action, ['create', 'bulk'], true)) {
        $created = $this->application->environment_variables()
            ->where('key', 'VCC_OWNER_NEW')->firstOrFail();

        expect($created->value)->toBe('new-fictional-value');
    }

    Process::assertNothingRan();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['create', 'update', 'bulk', 'delete']);

test('application sharing management is limited to the creator and owning team owner', function (
    string $role,
    bool $creator,
    bool $allowed
) {
    $user = User::factory()->create();
    $user->teams()->attach($this->team, ['role' => $role]);

    if ($creator) {
        $this->application->forceFill(['created_by' => $user->id])->save();
    }

    expect($user->can('manageSharing', $this->application))->toBe($allowed);
})->with([
    'owning team owner' => ['owner', false, true],
    'admin creator' => ['admin', true, true],
    'operator creator' => ['operator', true, true],
    'member creator after role change' => ['member', true, true],
    'admin non-creator' => ['admin', false, false],
    'operator non-creator' => ['operator', false, false],
    'member non-creator' => ['member', false, false],
]);

test('application creator cannot manage sharing after leaving the owning team', function () {
    $creator = User::factory()->create();
    $creator->teams()->attach($this->team, ['role' => 'operator']);

    $this->application->forceFill(['created_by' => $creator->id])->save();

    expect($creator->can('manageSharing', $this->application))->toBeTrue();

    $creator->teams()->detach($this->team);

    expect($creator->fresh()->can('manageSharing', $this->application))
        ->toBeFalse();
});

test('owner of another team cannot manage application sharing', function () {
    $otherTeam = Team::factory()->create();
    $otherOwner = User::factory()->create();
    $otherOwner->teams()->attach($otherTeam, ['role' => 'owner']);

    expect($otherOwner->can('manageSharing', $this->application))->toBeFalse();
});
