<?php

use App\Livewire\Dashboard;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setupDashboardUser(string $role): array
{
    $team = Team::factory()->create();

    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);

    return [$user, $team];
}

function createProjectForTeam(Team $team): Project
{
    return Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $team->id,
    ]);
}

function createPrivateKeyForTeam(Team $team): void
{
    DB::table('private_keys')->insert([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('admin sees add project button on dashboard', function () {
    [$user, $team] = setupDashboardUser('admin');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $project = createProjectForTeam($team);
    $environment = $project->environments()->firstOrFail();
    $createResourceUrl = route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee($createResourceUrl, false);
});

test('member does not see add project button on dashboard', function () {
    [$user, $team] = setupDashboardUser('member');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $project = createProjectForTeam($team);
    $environment = $project->environments()->firstOrFail();
    $createResourceUrl = route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]);

    Livewire::test(Dashboard::class)
        ->assertDontSee($createResourceUrl, false);
});

test('admin sees add server button on dashboard', function () {
    [$user, $team] = setupDashboardUser('admin');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createPrivateKeyForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertSee(route('server.create'));
});

test('member does not see add server button on dashboard', function () {
    [$user, $team] = setupDashboardUser('member');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createPrivateKeyForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertDontSee(route('server.create'));
});
