<x-application.settings-section
    id="visibility-sharing-section"
    title="Visibility and sharing"
    helper="Control who can discover this application and what shared users or teams can do.">
    <form wire:submit="saveVisibility">
        <div class="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
            <x-forms.listbox
                id="visibility"
                label="Visibility"
                :options="[
                    ['value' => 'team', 'label' => 'Team'],
                    ['value' => 'private', 'label' => 'Private'],
                    ['value' => 'custom', 'label' => 'Custom'],
                ]"
                helper="Team allows the owning team. Private allows only the creator and team owner. Custom uses the grants below." />

            <x-forms.button type="submit">
                Save visibility
            </x-forms.button>
        </div>

        @error('visibility')
            <p class="mt-2 text-sm text-error">{{ $message }}</p>
        @enderror
    </form>

    @if ($visibility === 'custom')
        <div class="mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
            <h3 class="mb-1 text-sm font-semibold text-black dark:text-fg">
                Explicit access
            </h3>
            <p class="mb-4 text-xs text-neutral-500 dark:text-fg-dim">
                Read grants visibility only. Operate also permits deployments and deployment operations.
            </p>

            <form wire:submit="saveShare">
                <div class="grid items-end gap-3 lg:grid-cols-[10rem_minmax(0,1fr)_10rem_auto]">
                    <x-forms.listbox
                        id="recipientType"
                        label="Recipient type"
                        live
                        :options="[
                            ['value' => 'user', 'label' => 'User'],
                            ['value' => 'team', 'label' => 'Team'],
                        ]" />

                    <x-forms.listbox
                        id="recipientId"
                        label="Recipient"
                        placeholder="Select a recipient"
                        :options="$this->recipientOptions" />

                    <x-forms.listbox
                        id="permission"
                        label="Permission"
                        :options="[
                            ['value' => 'read', 'label' => 'Read'],
                            ['value' => 'operate', 'label' => 'Operate'],
                        ]" />

                    <x-forms.button type="submit">
                        Add access
                    </x-forms.button>
                </div>
            </form>

            <div class="mt-5 space-y-2">
                @forelse ($this->shares as $share)
                    <div
                        class="flex items-center gap-3 rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]"
                        wire:key="application-share-{{ $share->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-black dark:text-fg">
                                @if ($share->user)
                                    {{ $share->user->name }} ({{ $share->user->email }})
                                @elseif ($share->team)
                                    {{ $share->team->name }}
                                @else
                                    Removed recipient
                                @endif
                            </p>
                            <p class="text-xs text-neutral-500 dark:text-fg-dim">
                                {{ $share->user_id !== null ? 'User' : 'Team' }}
                                · {{ ucfirst($share->permission) }}
                            </p>
                        </div>

                        <button
                            type="button"
                            class="button shrink-0 text-error"
                            wire:click="removeShare({{ $share->id }})"
                            wire:confirm="Remove this application access?">
                            Remove
                        </button>
                    </div>
                @empty
                    <x-empty
                        size="sm"
                        title="No explicit access"
                        description="Add a user or team to share this application."
                        icon-name="teams" />
                @endforelse
            </div>
        </div>
    @elseif ($this->shares->isNotEmpty())
        <x-callout type="info" title="Saved custom grants are inactive" class="mt-5">
            Existing grants are retained but only take effect while visibility is Custom.
        </x-callout>
    @endif
</x-application.settings-section>
