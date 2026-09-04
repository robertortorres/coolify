<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ApplicationShare;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Sharing extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Application $application;

    public string $visibility = 'team';

    public string $recipientType = 'user';

    public ?string $recipientId = null;

    public string $permission = 'read';

    public function mount(Application $application): void
    {
        $this->authorize('manageSharing', $application);

        $application->refresh();

        $this->application = $application;
        $this->visibility = $application->visibility ?? 'team';
    }

    #[Computed]
    public function userOptions(): array
    {
        $teamId = $this->application->team()?->id;

        if ($teamId === null) {
            return [];
        }

        return User::query()
            ->whereHas('teams', fn ($query) => $query->whereKey($teamId))
            ->orderByRaw('LOWER(name)')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn (User $user) => [
                'value' => (string) $user->id,
                'label' => $user->name.' ('.$user->email.')',
            ])
            ->all();
    }

    #[Computed]
    public function teamOptions(): array
    {
        $ownerTeamId = $this->application->team()?->id;

        if ($ownerTeamId === null) {
            return [];
        }

        return Team::query()
            ->whereKeyNot(0)
            ->whereKeyNot($ownerTeamId)
            ->orderByRaw('LOWER(name)')
            ->get(['id', 'name'])
            ->map(fn (Team $team) => [
                'value' => (string) $team->id,
                'label' => $team->name,
            ])
            ->all();
    }

    #[Computed]
    public function recipientOptions(): array
    {
        return $this->recipientType === 'team'
            ? $this->teamOptions
            : $this->userOptions;
    }

    #[Computed]
    public function shares(): Collection
    {
        return ApplicationShare::query()
            ->where('application_id', $this->application->id)
            ->with([
                'user:id,name,email',
                'team:id,name',
            ])
            ->orderBy('id')
            ->get();
    }

    public function updatedRecipientType(): void
    {
        $this->recipientId = null;
    }

    public function saveVisibility(): void
    {
        $this->authorizeFreshApplication();

        $this->validate([
            'visibility' => ['required', Rule::in(['team', 'private', 'custom'])],
        ]);

        $previousVisibility = $this->application->visibility;

        $this->application->forceFill([
            'visibility' => $this->visibility,
        ])->save();

        auditLog('ui.application.sharing.visibility_updated', [
            'team_id' => $this->application->team()?->id,
            'application_uuid' => $this->application->uuid,
            'application_name' => $this->application->name,
            'previous_visibility' => $previousVisibility,
            'visibility' => $this->visibility,
        ]);

        $this->dispatch('success', 'Application visibility updated.');
    }

    public function saveShare(): void
    {
        $this->authorizeFreshApplication();

        if ($this->application->visibility !== 'custom') {
            $this->addError(
                'visibility',
                'Set application visibility to Custom before adding shares.'
            );

            return;
        }

        $this->validate([
            'recipientType' => ['required', Rule::in(['user', 'team'])],
            'recipientId' => [
                'required',
                'integer',
                Rule::in($this->allowedRecipientIds()),
            ],
            'permission' => ['required', Rule::in(['read', 'operate'])],
        ]);

        $recipientId = (int) $this->recipientId;
        $recipientColumn = $this->recipientType === 'team'
            ? 'team_id'
            : 'user_id';
        $otherColumn = $this->recipientType === 'team'
            ? 'user_id'
            : 'team_id';

        $share = ApplicationShare::query()->updateOrCreate(
            [
                'application_id' => $this->application->id,
                $recipientColumn => $recipientId,
            ],
            [
                $otherColumn => null,
                'permission' => $this->permission,
                'granted_by' => auth()->id(),
            ]
        );

        auditLog('ui.application.sharing.share_saved', [
            'team_id' => $this->application->team()?->id,
            'application_uuid' => $this->application->uuid,
            'application_name' => $this->application->name,
            'recipient_type' => $this->recipientType,
            'recipient_id' => $recipientId,
            'permission' => $share->permission,
        ]);

        $this->recipientId = null;
        unset($this->shares);

        $this->dispatch('success', 'Application access updated.');
    }

    public function removeShare(int $shareId): void
    {
        $this->authorizeFreshApplication();

        $share = ApplicationShare::query()
            ->where('application_id', $this->application->id)
            ->whereKey($shareId)
            ->first();

        if ($share === null) {
            $this->dispatch('error', 'Application access not found.');

            return;
        }

        $recipientType = $share->team_id !== null ? 'team' : 'user';
        $recipientId = $share->team_id ?? $share->user_id;
        $permission = $share->permission;

        $share->delete();

        auditLog('ui.application.sharing.share_removed', [
            'team_id' => $this->application->team()?->id,
            'application_uuid' => $this->application->uuid,
            'application_name' => $this->application->name,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'permission' => $permission,
        ]);

        unset($this->shares);

        $this->dispatch('success', 'Application access removed.');
    }

    private function authorizeFreshApplication(): void
    {
        $this->application->refresh();
        $this->authorize('manageSharing', $this->application);
    }

    private function allowedRecipientIds(): array
    {
        $options = $this->recipientType === 'team'
            ? $this->teamOptions
            : $this->userOptions;

        return collect($options)
            ->pluck('value')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function render()
    {
        return view('livewire.project.application.sharing');
    }
}
