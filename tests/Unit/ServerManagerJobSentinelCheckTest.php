<?php

it('does not schedule periodic Sentinel restart checks', function () {
    $root = dirname(__DIR__, 2);
    $manager = file_get_contents($root.'/app/Jobs/ServerManagerJob.php');
    $diagnostics = file_get_contents($root.'/app/Console/Commands/ScheduledJobDiagnostics.php');

    expect($manager)
        ->not->toContain('sentinel-restart:')
        ->not->toContain('CheckAndStartSentinelJob::dispatch($server)')
        ->and($diagnostics)
        ->not->toContain('sentinel-restart:');
});
