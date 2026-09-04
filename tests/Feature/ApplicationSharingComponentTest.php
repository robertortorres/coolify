<?php

use App\Livewire\Project\Application\Sharing;
use App\Models\Application;
use App\Models\ApplicationShare;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();

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
    $destination = StandaloneDocker::query()
        ->where('server_id', $server->id)
        ->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create([
        'project_id' => $project->id,
    ]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

test('owning team owner can mount application sharing', function () {
    Livewire::test(Sharing::class, ['application' => $this->application])
        ->assertOk()
        ->assertSet('visibility', 'team');
});

test('application creator can mount application sharing', function () {
    $creator = User::factory()->create();
    $creator->teams()->attach($this->team, ['role' => 'operator']);

    $this->application->forceFill(['created_by' => $creator->id])->save();

    $this->actingAs($creator);
    session(['currentTeam' => $this->team]);

    Livewire::test(Sharing::class, ['application' => $this->application])
        ->assertOk();
});

test('non-creator team admin cannot mount application sharing', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Sharing::class, ['application' => $this->application])
        ->assertForbidden();
});

test('sharing manager can update application visibility', function (
    string $visibility
) {
    Livewire::test(Sharing::class, ['application' => $this->application])
        ->set('visibility', $visibility)
        ->call('saveVisibility')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->application->fresh()->visibility)->toBe($visibility);

    $this->assertDatabaseHas('audit_events', [
        'team_id' => $this->team->id,
        'event' => 'ui.application.sharing.visibility_updated',
        'resource_uuid' => $this->application->uuid,
    ]);
})->with(['private', 'custom', 'team']);

test('sharing manager cannot save an unsupported visibility', function () {
    Livewire::test(Sharing::class, ['application' => $this->application])
        ->set('visibility', 'public')
        ->call('saveVisibility')
        ->assertHasErrors(['visibility']);

    expect($this->application->fresh()->visibility)->toBe('team');
});

test('sharing manager can grant access to an owning team user', function (
    string $permission
) {
    $recipient = User::factory()->create();
    $recipient->teams()->attach($this->team, ['role' => 'member']);

    $this->application->forceFill(['visibility' => 'custom'])->save();

    Livewire::test(Sharing::class, ['application' => $this->application])
        ->set('recipientType', 'user')
        ->set('recipientId', (string) $recipient->id)
        ->set('permission', $permission)
        ->call('saveShare')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->assertDatabaseHas('application_shares', [
        'application_id' => $this->application->id,
        'user_id' => $recipient->id,
        'team_id' => null,
        'permission' => $permission,
        'granted_by' => $this->owner->id,
    ]);
})->with(['read', 'operate']);

test('sharing manager can grant access to another team', function () {
    $recipientTeam = Team::factory()->create();

    $this->application->forceFill(['visibility' => 'custom'])->save();

    Livewire::test(Sharing::class, ['application' => $this->application])
        ->set('recipientType', 'team')
        ->set('recipientId', (string) $recipientTeam->id)
        ->set('permission', 'operate')
        ->call('saveShare')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $this->assertDatabaseHas('application_shares', [
        'application_id' => $this->application->id,
        'user_id' => null,
        'team_id' => $recipientTeam->id,
        'permission' => 'operate',
    ]);
});

test('sharing manager cannot inject an unrelated user id', function () {
    $unrelated = User::factory()->create();

    $this->application->forceFill(['visibility' => 'custom'])->save();

    Livewire::test(Sharing::class, ['application' => $this->application])
        ->set('recipientType', 'user')
        ->set('recipientId', (string) $unrelated->id)
        ->set('permission', 'operate')
        ->call('saveShare')
        ->assertHasErrors(['recipientId']);

    expect(ApplicationShare::count())->toBe(0);
});

test('sharing manager cannot grant the application to its owning team', function () {
    $this->application->forceFill(['visibility' => 'custom'])->save();

    Livewire::test(Sharing::class, ['application' => $this->application])
        ->set('recipientType', 'team')
        ->set('recipientId', (string) $this->team->id)
        ->set('permission', 'read')
        ->call('saveShare')
        ->assertHasErrors(['recipientId']);

    expect(ApplicationShare::count())->toBe(0);
});

test('shares cannot be added unless visibility is custom', function () {
    $recipient = User::factory()->create();
    $recipient->teams()->attach($this->team, ['role' => 'member']);

    Livewire::test(Sharing::class, ['application' => $this->application])
        ->set('recipientType', 'user')
        ->set('recipientId', (string) $recipient->id)
        ->call('saveShare')
        ->assertHasErrors(['visibility']);

    expect(ApplicationShare::count())->toBe(0);
});

test('sharing manager can remove an application share', function () {
    $recipient = User::factory()->create();

    $share = ApplicationShare::create([
        'application_id' => $this->application->id,
        'user_id' => $recipient->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    Livewire::test(Sharing::class, ['application' => $this->application])
        ->call('removeShare', $share->id)
        ->assertDispatched('success');

    $this->assertDatabaseMissing('application_shares', [
        'id' => $share->id,
    ]);
});

test('sharing manager cannot remove a share belonging to another application', function () {
    $otherApplication = Application::factory()->create([
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);

    $recipient = User::factory()->create();

    $share = ApplicationShare::create([
        'application_id' => $otherApplication->id,
        'user_id' => $recipient->id,
        'permission' => 'read',
        'granted_by' => $this->owner->id,
    ]);

    Livewire::test(Sharing::class, ['application' => $this->application])
        ->call('removeShare', $share->id)
        ->assertDispatched('error', 'Application access not found.');

    $this->assertDatabaseHas('application_shares', [
        'id' => $share->id,
        'application_id' => $otherApplication->id,
    ]);
});

test('sharing actions reauthorize after creator leaves the owning team', function () {
    $creator = User::factory()->create();
    $creator->teams()->attach($this->team, ['role' => 'operator']);

    $this->application->forceFill(['created_by' => $creator->id])->save();

    $this->actingAs($creator);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(
        Sharing::class,
        ['application' => $this->application]
    )->assertOk();

    $creator->teams()->detach($this->team);

    $component
        ->set('visibility', 'private')
        ->call('saveVisibility')
        ->assertForbidden();

    expect($this->application->fresh()->visibility)->toBe('team');
});
