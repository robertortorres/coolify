<?php

namespace App\Livewire\Team;

use App\Actions\User\RevokeUserTeamTokens;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Member extends Component
{
    use AuthorizesRequests;

    public User $member;

    public function makeAdmin()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            $this->ensureCanManageMember(Role::ADMIN);
            $teamId = currentTeam()->id;
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::ADMIN->value]);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            $this->auditRoleUpdate($teamId, Role::ADMIN);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeOwner()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            $this->ensureCanManageMember(Role::OWNER);
            $teamId = currentTeam()->id;
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::OWNER->value]);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            $this->auditRoleUpdate($teamId, Role::OWNER);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeOperator()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            $this->ensureCanManageMember(Role::ADMIN);
            $teamId = currentTeam()->id;
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::OPERATOR->value]);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            $this->auditRoleUpdate($teamId, Role::OPERATOR);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeReadonly()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            $this->ensureCanManageMember(Role::ADMIN);
            $teamId = currentTeam()->id;
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::MEMBER->value]);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            $this->auditRoleUpdate($teamId, Role::MEMBER);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function remove()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            $this->ensureCanManageMember(Role::ADMIN);
            $teamId = currentTeam()->id;
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->detach($teamId);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            auditLog('ui.team_member.removed', [
                'team_id' => $teamId,
                'member_id' => $this->member->id,
                'member_name' => $this->member->name,
                'member_email' => $this->member->email,
            ]);
            // Clear cache for the removed user - both old and new key formats
            Cache::forget("team:{$this->member->id}");
            Cache::forget("user:{$this->member->id}:team:{$teamId}");
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function ensureCanManageMember(Role $requiredRole): void
    {
        $user = auth()->user();

        if ($this->member->is($user)) {
            throw new \Exception('You cannot change your own team role.');
        }

        $memberRole = $this->getMemberRole();
        if (is_null($memberRole)) {
            throw new \Exception('The selected user is not a member of this team.');
        }

        if ($user->isInstanceAdmin()) {
            return;
        }

        $userRole = $user->role();
        if (is_null($userRole)) {
            throw new \Exception('You are not authorized to perform this action.');
        }

        $role = Role::from($userRole);
        if ($role->lt($requiredRole) || Role::from($memberRole)->gt($role)) {
            throw new \Exception('You are not authorized to perform this action.');
        }
    }

    private function getMemberRole(): ?string
    {
        return $this->member->teams()
            ->where('teams.id', currentTeam()->id)
            ->first()?->pivot?->role;
    }

    private function auditRoleUpdate(int $teamId, Role $role): void
    {
        auditLog('ui.team_member.role_updated', [
            'team_id' => $teamId,
            'member_id' => $this->member->id,
            'member_name' => $this->member->name,
            'member_email' => $this->member->email,
            'role' => $role->value,
        ]);
    }
}
