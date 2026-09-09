<?php

/**
 * Unit tests to verify that the "new image" quick action properly matches
 * the docker-image type using the quickcommand field.
 *
 * This test verifies the fix for the issue where typing "new image" would
 * not match because the frontend was only checking name and type fields,
 * not the quickcommand field.
 */
it('ensures GlobalSearch blade template checks quickcommand field in matching logic', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    // Check that the matching logic includes quickcommand check
    expect($bladeFile)
        ->toContain('item.quickcommand')
        ->toContain('quickcommand.toLowerCase().includes(trimmed)');
});

it('ensures GlobalSearch clears search query when starting resource creation', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');

    // Check that navigateToResourceCreation clears the search query
    expect($globalSearchFile)
        ->toContain('$this->searchQuery = \'\'');
});

it('ensures GlobalSearch uses redirect route helper', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');

    // Check that completeResourceCreation uses the shared redirect route helper
    expect($globalSearchFile)
        ->toContain('redirectRoute($this, \'project.resource.create\'');
});

it('routes new server quick action to the new server page', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    expect($globalSearchFile)
        ->toContain("if (\$type === 'server')")
        ->toContain("redirectRoute(\$this, 'server.create')");

    expect($bladeFile)
        ->toContain("window.location.href = '/servers/new'")
        ->not->toContain('@open-create-modal-server.window');
});

it('ensures docker-image item has quickcommand with new image', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');

    // Check that Docker Image has the correct quickcommand
    expect($globalSearchFile)
        ->toContain("'name' => 'Docker Image'")
        ->toContain("'quickcommand' => '(type: new image)'")
        ->toContain("'type' => 'docker-image'");
});

it('uses command palette styling and transitions for GlobalSearch quick actions', function () {
    $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    preg_match_all('/<button\b[^>]*@click="runPaletteTransition\(\(\) => \$wire\.navigateToResource\(item\.type\)\)"[^>]*>/s', $blade, $buttons);

    expect($buttons[0])->not->toBeEmpty();

    foreach ($buttons[0] as $button) {
        expect($button)->toContain('search-result-item command-palette-item');
    }
});

it('uses product logos for GlobalSearch database quick actions', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    expect($bladeFile)
        ->toContain(":src=\"item.logo.startsWith('http') ? item.logo : '/' + item.logo\"");

    foreach ([
        'postgresql' => 'svgs/postgresql.svg',
        'mysql' => 'svgs/mysql.svg',
        'mariadb' => 'svgs/mariadb.svg',
        'redis' => 'svgs/redis.svg',
        'keydb' => 'svgs/keydb.svg',
        'dragonfly' => 'svgs/dragonfly.svg',
        'mongodb' => 'svgs/mongodb.svg',
        'clickhouse' => 'svgs/clickhouse-icon.svg',
    ] as $type => $logo) {
        expect($globalSearchFile)
            ->toContain("'type' => '{$type}'")
            ->toContain("'logo' => '{$logo}'");

        expect(file_exists(__DIR__.'/../../public/'.$logo))->toBeTrue();
    }
});

it('uses shared palette styles with visible keyboard focus for existing resources', function () {
    $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

    preg_match_all('/<a\b[^>]*:href="result\.link[^"]*"[^>]*>/s', $blade, $links);

    expect($links[0])->not->toBeEmpty();

    foreach ($links[0] as $link) {
        expect($link)->toContain('search-result-item command-palette-item');
    }

    expect($css)
        ->toMatch('/\.command-palette-item:hover\s*\{[^}]*background:\s*var\(--coollabs-fill\)/s')
        ->toMatch('/a\.command-palette-item:focus-visible\s*\{[^}]*background:\s*color-mix\(/s')
        ->toMatch('/\.command-palette-item:focus-visible::before\s*\{[^}]*background:\s*var\(--color-accent\)/s');
});

it('uses visible cropped SVG marks for wide database logos', function () {
    $keydbLogo = file_get_contents(__DIR__.'/../../public/svgs/keydb.svg');
    $dragonflyLogo = file_get_contents(__DIR__.'/../../public/svgs/dragonfly.svg');
    $clickhouseLogo = file_get_contents(__DIR__.'/../../public/svgs/clickhouse-icon.svg');

    expect($keydbLogo)
        ->toContain('viewBox="0 0 160 182"')
        ->toContain('svg{color:#d4d4d4}')
        ->not->toContain('prefers-color-scheme');

    expect($dragonflyLogo)
        ->toContain('viewBox="0 0 88 88"')
        ->toContain('svg{color:#d4d4d4}')
        ->not->toContain('prefers-color-scheme');

    expect($clickhouseLogo)
        ->toContain('viewBox="1.70837 1.875 22.25025 22.2493"')
        ->toContain('svg{color:#d4d4d4}')
        ->toContain('x="20.7087"')
        ->toContain('width="2.24992"')
        ->not->toContain('viewBox="0 0 100 43"')
        ->not->toContain('width="215"')
        ->not->toContain('height="90"');
});

it('uses contained image assets instead of inline database logos', function () {
    $globalSearch = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');
    $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

    expect($blade)
        ->toContain('class="command-palette-item-icon"')
        ->toContain('<img :src="item.logo.startsWith(\'http\') ? item.logo : \'/\' + item.logo"')
        ->toContain(':alt="item.name"')
        ->toContain('x-on:error="if (item.logo_cdn_url')
        ->toContain('item.logo_default_url')
        ->not->toContain('$item[\'logo_html\']')
        ->not->toContain('x-html="item.logo_html"');

    expect($css)
        ->toMatch('/\.command-palette-item-icon img\s*\{[^}]*object-fit:\s*contain;/s');

    expect($globalSearch)->not->toContain("'logo_html' =>");
});

it('uses neutral plus icons for GlobalSearch creatable actions without logos', function () {
    $blade = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

    preg_match('/<template x-if="!item\.logo">(.*?)<\/template>/s', $blade, $fallback);

    expect($fallback)->toHaveKey(1);
    expect($fallback[1])
        ->toContain('class="command-palette-item-icon is-create"')
        ->toContain('d="M6 12H18"')
        ->toContain('d="M12 18V6"');

    expect($css)
        ->toMatch('/\.command-palette-item-icon\.is-create\s*\{[^}]*background:\s*var\(--coollabs-recessed\)/s')
        ->toMatch('/\.command-palette-item-icon\.is-create\s*\{[^}]*color:\s*var\(--coollabs-subtle\)/s');
});
