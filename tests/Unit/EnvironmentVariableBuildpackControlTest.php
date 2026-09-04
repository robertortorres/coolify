<?php

use App\Models\EnvironmentVariable;
use App\Models\SharedEnvironmentVariable;

it('flags NIXPACKS_ keys as buildpack control variables', function () {
    $env = new EnvironmentVariable;
    $env->key = 'NIXPACKS_NODE_VERSION';

    expect($env->is_buildpack_control)->toBeTrue();
});

it('flags RAILPACK_ keys as buildpack control variables', function () {
    $env = new EnvironmentVariable;
    $env->key = 'RAILPACK_NODE_VERSION';

    expect($env->is_buildpack_control)->toBeTrue();
});

it('does not flag user-defined keys as buildpack control variables', function () {
    $env = new EnvironmentVariable;
    $env->key = 'MY_BUILD_VAR';

    expect($env->is_buildpack_control)->toBeFalse();
});

it('does not flag empty key as buildpack control variable', function () {
    $env = new EnvironmentVariable;

    expect($env->is_buildpack_control)->toBeFalse();
});

it('lists is_buildpack_control in appends and drops legacy is_nixpacks', function () {
    $env = new EnvironmentVariable;

    expect($env->getAppends())->toContain('is_buildpack_control');
    expect($env->getAppends())->not->toContain('is_nixpacks');
});

it('normalizes environment variable keys before storing them on the model', function () {
    $env = new EnvironmentVariable;
    $env->key = ' node.name ';

    expect($env->key)->toBe('node.name');
});

it('allows keys matching the configured identifier format on both models', function (string $model, string $key) {
    $env = new $model;
    $env->key = $key;

    expect($env->key)->toBe($key);
})->with([
    'application variable' => [EnvironmentVariable::class],
    'shared variable' => [SharedEnvironmentVariable::class],
])->with([
    'uppercase underscore' => 'APP_KEY',
    'leading underscore' => '_APP1',
    'dot' => 'node.name',
    'uppercase dots' => 'XPACK.SECURITY.ENABLED',
]);

it('rejects keys outside the configured identifier format on both models', function (string $model, string $key) {
    $env = new $model;
    $env->key = 'SAFE_KEY';

    expect(function () use ($env, $key) {
        $env->key = $key;
    })->toThrow(
        InvalidArgumentException::class,
        'must start with a letter or underscore'
    );

    expect($env->key)->toBe('SAFE_KEY');
})->with([
    'application variable' => [EnvironmentVariable::class],
    'shared variable' => [SharedEnvironmentVariable::class],
])->with([
    'equals sign' => 'BAD=KEY',
    'starts with digit' => '1BAD',
    'hyphen' => 'BAD-KEY',
    'semicolon' => 'BAD;KEY',
    'embedded space' => 'BAD KEY',
    'command substitution' => 'BAD$(id)',
]);
