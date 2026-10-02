<?php

use App\Livewire\Dashboard;
use App\Livewire\Server\Index as ServerIndex;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->create(['id' => 0])
    );

    $this->ownerTeam = Team::factory()->create([
        'name' => 'Infrastructure',
    ]);

    $this->consumerTeam = Team::factory()->create([
        'name' => 'General',
    ]);

    $this->user = User::factory()->create();

    $this->user->teams()->attach($this->consumerTeam, [
        'role' => 'owner',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->consumerTeam]);

    $this->sharedServer = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
        'name' => 'server6-deploy-dev',
        'description' => 'Shared deployment server',
    ]);

    $this->sharedServer->settings()->update([
        'is_build_server' => false,
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);
});

it('shows an authorized shared deployment server on the server index', function () {
    $this->sharedServer->sharedTeams()->attach(
        $this->consumerTeam->id,
        [
            'can_build' => false,
            'can_deploy' => true,
        ]
    );

    Livewire::test(ServerIndex::class)
        ->assertSee('server6-deploy-dev')
        ->assertSee('Shared')
        ->assertSee('Infrastructure')
        ->assertDontSeeHtml(
            route('server.show', ['server_uuid' => $this->sharedServer->uuid])
        );
});

it('does not show an unauthorized shared server on the server index', function () {
    $this->sharedServer->sharedTeams()->attach(
        $this->consumerTeam->id,
        [
            'can_build' => false,
            'can_deploy' => false,
        ]
    );

    Livewire::test(ServerIndex::class)
        ->assertDontSee('server6-deploy-dev');
});

it('keeps an owned server linked to its administrative page', function () {
    $ownedServer = Server::factory()->create([
        'team_id' => $this->consumerTeam->id,
        'name' => 'general-owned-server',
    ]);

    $ownedServer->settings()->update([
        'is_build_server' => false,
    ]);

    Livewire::test(ServerIndex::class)
        ->assertSee('general-owned-server')
        ->assertSeeHtml(
            route('server.show', ['server_uuid' => $ownedServer->uuid])
        );
});

it('shows shared deployment infrastructure without requiring a consumer private key', function () {
    $this->sharedServer->sharedTeams()->attach(
        $this->consumerTeam->id,
        [
            'can_build' => false,
            'can_deploy' => true,
        ]
    );

    Livewire::test(Dashboard::class)
        ->assertSee('server6-deploy-dev')
        ->assertSee('Shared')
        ->assertSee('Infrastructure')
        ->assertDontSee('A private key is required')
        ->assertDontSee('Add private key')
        ->assertDontSeeHtml(
            route('server.show', ['server_uuid' => $this->sharedServer->uuid])
        );
});
