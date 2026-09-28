<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => true,
        ]);
    });

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'Pure Dockerfile Example',
        'status' => 'running',
    ]);
});

it('shows the compact application heading below xl while keeping desktop actions in the top bar', function () {
    $response = $this->get(route('project.application.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));

    $response
        ->assertSuccessful()
        ->assertSee('mb-3 w-full xl:hidden', false)
        ->assertSee('flex min-w-0 flex-col items-start gap-2', false)
        ->assertSee('relative flex w-full min-w-0 items-center gap-2', false)
        ->assertSee('application-mobile-actions', false)
        ->assertSee('hidden w-full items-center xl:flex xl:w-auto', false)
        ->assertSee('resource-heading-navbar application-heading-actions', false)
        ->assertSee('Pure Dockerfile Example')
        ->assertSee('Running')
        ->assertSee('w-full max-w-none pb-4 md:pb-6 lg:pb-0', false);

    expect($response->getContent())
        ->not->toContain('flex min-w-0 flex-col gap-1 md:hidden')
        ->not->toContain('hidden pt-2 pb-10 md:flex');
});
