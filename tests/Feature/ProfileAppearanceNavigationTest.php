<?php

it('uses the account menu as the only profile navigation', function () {
    $routes = file_get_contents(base_path('routes/web.php'));
    $profileView = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));

    expect($routes)
        ->toContain("Route::get('/profile/appearance', ProfileAppearance::class)->name('profile.appearance')")
        ->and($profileView)
        ->not->toContain('<x-profile.navbar />')
        ->not->toContain('<h1>Profile</h1>\n    <div class="subtitle -mt-2">');
});

it('opens the email change form without a Livewire request', function () {
    $profileView = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));

    expect($profileView)
        ->toContain('@click="openEmailModal()"')
        ->toContain('this.$refs.newEmailInput?.focus()')
        ->toContain('x-ref="newEmailInput"')
        ->toContain('x-show="emailModalOpen"')
        ->toContain('x-teleport="body"')
        ->toContain('Change email')
        ->toContain('Send code')
        ->toContain('Verify email')
        ->not->toContain('>Cancel</x-forms.button>')
        ->not->toContain('wire:click="showEmailChangeForm"');
});

it('shows the two-factor authentication state as a header badge', function () {
    $profileView = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));

    expect($profileView)
        ->toContain('<x-status-badge status="Enabled" type="success" />');
});

it('offers full and centered page width preferences on the profile appearance view', function () {
    $appearanceView = file_get_contents(resource_path('views/livewire/profile/appearance.blade.php'));
    $themeControls = file_get_contents(resource_path('views/components/theme-controls.blade.php'));
    $baseLayout = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $appLayout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($appearanceView)
        ->not->toContain('<x-profile.navbar />')
        ->toContain('<x-theme-controls variant="full" />')
        ->and($themeControls)
        ->toContain('Color theme')
        ->toContain('Page width')
        ->toContain("['value' => 'full'")
        ->toContain("['value' => 'centered'")
        ->toContain("@click=\"setWidth('{{ \$option['value'] }}')\"")
        ->not->toContain('Interface density')
        ->not->toContain('setZoom(')
        ->and($baseLayout)
        ->toContain("pageWidth: localStorage.getItem('pageWidth') || 'full'")
        ->toContain("localStorage.setItem('pageWidth', width)")
        ->and($appLayout)
        ->toContain("pageWidth: localStorage.getItem('pageWidth') || 'full'")
        ->toContain('@page-width-changed.window="pageWidth = $event.detail"')
        ->toContain("pageWidth === 'centered' ? 'mx-auto max-w-[1400px]' : 'max-w-none'");
});
