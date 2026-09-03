<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Add;
use Livewire\Livewire;

it('rejects environment variable keys outside the configured identifier format', function (string $key) {
    Livewire::test(Add::class)
        ->set('key', $key)
        ->set('value', 'value')
        ->call('submit')
        ->assertHasErrors(['key' => 'regex'])
        ->assertNotDispatched('saveKey');
})->with([
    'equals sign' => 'BAD=KEY',
    'starts with digit' => '1BAD',
    'hyphen' => 'BAD-KEY',
    'embedded space' => 'BAD KEY',
    'command substitution' => 'BAD$(id)',
]);

it('allows environment variable keys matching the configured identifier format', function (string $key) {
    Livewire::test(Add::class)
        ->set('key', $key)
        ->set('value', 'value')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('saveKey', function ($event, array $data) use ($key) {
            return data_get($data, 'key') === $key || data_get($data, '0.key') === $key;
        });
})->with([
    'uppercase underscore' => 'APP_KEY',
    'leading underscore' => '_APP1',
    'dot' => 'node.name',
    'uppercase dots' => 'XPACK.SECURITY.ENABLED',
]);

it('trims surrounding whitespace in environment variable keys in the add form', function () {
    Livewire::test(Add::class)
        ->set('key', ' node.name ')
        ->set('value', 'value')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('saveKey', function ($event, array $data) {
            return data_get($data, 'key') === 'node.name' || data_get($data, '0.key') === 'node.name';
        });
});
