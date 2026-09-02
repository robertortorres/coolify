<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\ApplicationShare;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class ApplicationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Application $application): bool
    {
        $teamId = $this->getTeamId($application);

        return $this->sharingAccess($user, $application);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->canManageResources();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Application $application): Response
    {
        $teamId = $this->getTeamId($application);

        if ($teamId === null) {
            return Response::deny('Application team not found.');
        }

        if ($this->sharingAccess($user, $application, true)) {
            return Response::allow();
        }

        return Response::deny('You need at least operator permissions to update this application.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Application $application): bool
    {
        $teamId = $this->getTeamId($application);

        return $this->sharingAccess($user, $application, true);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Application $application): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Application $application): bool
    {
        return false;
    }

    /**
     * Determine whether the user can upload a backup archive for this application.
     */
    public function uploadBackup(User $user, Application $application): Response
    {
        $teamId = $this->getTeamId($application);

        if ($teamId === null) {
            return Response::deny('Application team not found.');
        }

        if ($this->sharingAccess($user, $application, true)) {
            return Response::allow();
        }

        return Response::deny('You need at least operator permissions to upload backups for this application.');
    }

    /**
     * Determine whether the user can deploy the application.
     */
    public function deploy(User $user, Application $application): bool
    {
        return $this->canOperateApplication($user, $application);
    }

    /**
     * Determine whether the user can manage deployments.
     */
    public function manageDeployments(User $user, Application $application): bool
    {
        return $this->canOperateApplication($user, $application);
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, Application $application): bool
    {
        $teamId = $this->getTeamId($application);

        return $this->sharingAccess($user, $application, true);
    }

    /**
     * Determine whether the user can cleanup deployment queue.
     */
    public function cleanupDeploymentQueue(User $user): bool
    {
        return $user->canManageResources();
    }

    private function sharingAccess(
        User $user,
        Application $application,
        bool $manage = false
    ): bool {
        $teamId = $this->getTeamId($application);
        if ($teamId === null) {
            return false;
        }

        $visibility = $application->visibility ?? 'team';
        if (! in_array($visibility, ['team', 'private', 'custom'], true)) {
            return false;
        }

        $membership = $user->teams()->whereKey($teamId)->first();
        $role = $membership?->pivot?->role;
        $isCreator = $membership !== null
            && $application->created_by !== null
            && (string) $application->created_by === (string) $user->getKey();

        $trusted = $role === 'owner' || $isCreator;

        if ($manage) {
            return in_array($role, ['owner', 'admin', 'operator'], true)
                && ($visibility === 'team' || $trusted);
        }

        if ($visibility === 'team') {
            return $membership !== null;
        }

        if ($trusted) {
            return true;
        }

        if ($visibility === 'private') {
            return false;
        }

        return ApplicationShare::query()
            ->where('application_id', $application->getKey())
            ->whereIn('permission', ['read', 'operate'])
            ->where(function (Builder $query) use ($user): void {
                $query->where(function (Builder $direct) use ($user): void {
                    $direct->where('user_id', $user->getKey())
                        ->whereNull('team_id');
                })->orWhere(function (Builder $team) use ($user): void {
                    $team->whereNull('user_id')->whereIn(
                        'team_id',
                        $user->teams()->select('teams.id')
                    );
                });
            })
            ->exists();
    }

    private function canOperateApplication(
        User $user,
        Application $application
    ): bool {
        if ($this->sharingAccess($user, $application, true)) {
            return true;
        }

        if ($application->visibility !== 'custom'
            || $this->getTeamId($application) === null) {
            return false;
        }

        return ApplicationShare::query()
            ->where('application_id', $application->getKey())
            ->where('permission', 'operate')
            ->where(function (Builder $query) use ($user): void {
                $query->where(function (Builder $direct) use ($user): void {
                    $direct->where('user_id', $user->getKey())
                        ->whereNull('team_id');
                })->orWhere(function (Builder $team) use ($user): void {
                    $team->whereNull('user_id')->whereIn(
                        'team_id',
                        $user->teams()->select('teams.id')
                    );
                });
            })
            ->exists();
    }

    private function getTeamId(Application $application): ?int
    {
        return $application->team()?->id;
    }
}
