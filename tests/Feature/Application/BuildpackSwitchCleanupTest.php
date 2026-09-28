<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class GeneralWithoutBuildpackCleanupSubmitSideEffects extends General
{
    public function render(): mixed
    {
        return view('livewire.project.application.general');
    }

    public function submit($showToaster = true): void
    {
        $this->application->save();
    }
}

function buildpackSwitchApplicationAttributes($test): array
{
    return [
        'name' => 'buildpack-switch-test',
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'both',
    ];
}

beforeEach(function () {
    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->firstOrCreate(['id' => 0])
    );

    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Set current team
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    // Create project and environment
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
});

describe('Buildpack Switching Cleanup', function () {
    test('clears dockerfile fields when switching from dockerfile to nixpacks', function () {
        // Create an application with dockerfile buildpack and dockerfile content
        $application = Application::factory()->create([
            ...buildpackSwitchApplicationAttributes($this),
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM node:18\nHEALTHCHECK CMD curl -f http://localhost/ || exit 1',
            'dockerfile_location' => '/Dockerfile',
            'dockerfile_target_build' => 'production',
            'custom_healthcheck_found' => true,
        ]);

        // Switch to nixpacks buildpack
        Livewire::test(GeneralWithoutBuildpackCleanupSubmitSideEffects::class, ['application' => $application])
            ->assertSuccessful()
            ->set('buildPack', 'nixpacks')
            ->assertHasNoErrors();

        // Verify dockerfile fields were cleared
        $application->refresh();
        expect($application->build_pack)->toBe('nixpacks');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->dockerfile_target_build)->toBeNull();
        expect($application->custom_healthcheck_found)->toBeFalse();
    });

    test('clears dockerfile fields when switching from dockerfile to static', function () {
        $application = Application::factory()->create([
            ...buildpackSwitchApplicationAttributes($this),
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM nginx:alpine',
            'dockerfile_location' => '/custom.Dockerfile',
            'dockerfile_target_build' => 'prod',
            'custom_healthcheck_found' => true,
        ]);

        Livewire::test(GeneralWithoutBuildpackCleanupSubmitSideEffects::class, ['application' => $application])
            ->assertSuccessful()
            ->set('buildPack', 'static')
            ->assertHasNoErrors();

        $application->refresh();
        expect($application->build_pack)->toBe('static');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->dockerfile_target_build)->toBeNull();
        expect($application->custom_healthcheck_found)->toBeFalse();
    });

    test('does not clear dockerfile fields when switching to dockerfile', function () {
        $application = Application::factory()->create([
            ...buildpackSwitchApplicationAttributes($this),
            'build_pack' => 'nixpacks',
            'dockerfile' => null,
        ]);

        Livewire::test(GeneralWithoutBuildpackCleanupSubmitSideEffects::class, ['application' => $application])
            ->assertSuccessful()
            ->set('buildPack', 'dockerfile')
            ->assertHasNoErrors();

        // When switching TO dockerfile, fields remain as they were
        $application->refresh();
        expect($application->build_pack)->toBe('dockerfile');
    });

    test('does not affect fields when switching between non-dockerfile buildpacks', function () {
        $application = Application::factory()->create([
            ...buildpackSwitchApplicationAttributes($this),
            'build_pack' => 'nixpacks',
            'dockerfile' => null,
            'dockerfile_location' => null,
        ]);

        Livewire::test(GeneralWithoutBuildpackCleanupSubmitSideEffects::class, ['application' => $application])
            ->assertSuccessful()
            ->set('buildPack', 'static')
            ->assertHasNoErrors();

        $application->refresh();
        expect($application->build_pack)->toBe('static');
        expect($application->dockerfile)->toBeNull();
    });

    test('clears dockerfile fields when switching from dockerfile to railpack', function () {
        $application = Application::factory()->create([
            ...buildpackSwitchApplicationAttributes($this),
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM node:18',
            'dockerfile_location' => '/Dockerfile',
            'dockerfile_target_build' => 'production',
            'custom_healthcheck_found' => true,
        ]);

        Livewire::test(GeneralWithoutBuildpackCleanupSubmitSideEffects::class, ['application' => $application])
            ->assertSuccessful()
            ->set('buildPack', 'railpack')
            ->assertHasNoErrors();

        $application->refresh();
        expect($application->build_pack)->toBe('railpack');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->dockerfile_target_build)->toBeNull();
        expect($application->custom_healthcheck_found)->toBeFalse();
    });

    test('clears dockerfile fields when switching from dockerfile to dockercompose', function () {
        $application = Application::factory()->create([
            ...buildpackSwitchApplicationAttributes($this),
            'build_pack' => 'dockerfile',
            'dockerfile' => 'FROM alpine:latest',
            'dockerfile_location' => '/docker/Dockerfile',
            'custom_healthcheck_found' => true,
        ]);

        Livewire::test(GeneralWithoutBuildpackCleanupSubmitSideEffects::class, ['application' => $application])
            ->assertSuccessful()
            ->set('buildPack', 'dockercompose')
            ->assertHasNoErrors();

        $application->refresh();
        expect($application->build_pack)->toBe('dockercompose');
        expect($application->dockerfile)->toBeNull();
        expect($application->dockerfile_location)->toBeNull();
        expect($application->custom_healthcheck_found)->toBeFalse();
    });
});
