<?php

use Magicoli\ContainerTools\Container\ContainerDefinition;

require_once __DIR__ . '/Support.php';

test('Pasta forwards controller port', function () {
    $podman = integration_podman();
    $probeName = integration_name('pasta-probe-controller');
    $targetName = integration_name('pasta-controller');
    $created = [];

    try {
        integration_host_probe($podman, $probeName);
        $created[] = $probeName;
        $port = integration_unused_host_port($podman, $probeName);
        $result = $podman->create(new ContainerDefinition(
            $targetName,
            'docker.io/library/alpine:3.24',
            command: ['sh', '-c', 'sleep 300'],
            pastaAutoPublish: true,
        ));
        expect($result->status)->toBe(0, $result->stderr);
        $created[] = $targetName;
        expect($podman->start($targetName)->status)->toBe(0);
        $started = $podman->exec($targetName, ['sh', '-c', "busybox nc -lk -p $port -e /bin/cat >/dev/null 2>&1 </dev/null &"]);
        expect($started->status)->toBe(0, $started->stderr);
        expect(integration_wait_for_host_port($port))->toBeTrue('The Podman host could not reach the dynamically forwarded port.');
    } finally {
        foreach ($created as $name) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason('pasta') !== null, integration_skip_reason('pasta') ?? '');
