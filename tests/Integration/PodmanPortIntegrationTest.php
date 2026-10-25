<?php

use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

require_once __DIR__ . '/Support.php';

    test('EXPOSE host mappings', function () {
    $podman = integration_podman();
    $first = integration_name('ports-a');
    $second = integration_name('ports-b');
    $created = [];

    try {
        build_integration_image($podman, 'debian12');
        foreach ([$first, $second] as $name) {
            $result = $podman->create(new ContainerDefinition($name, integration_image('debian12')));
            expect($result->status)->toBe(0, $result->stderr);
            $created[] = $name;
            expect($result->data['container']['api']['Config']['ExposedPorts'])->toHaveKeys(['22/tcp', '80/tcp', '443/tcp']);
            $started = $podman->start($name);
            expect($started->status)->toBe(0, $started->stderr);
            expect($started->data['container']['ports'])->toHaveCount(3);
        }

        $firstPorts = array_column($podman->inspect($first)->data['container']['ports'], 'host_port');
        $secondPorts = array_column($podman->inspect($second)->data['container']['ports'], 'host_port');
        expect($firstPorts)->not->toContain('');
        expect($secondPorts)->not->toContain('');
        expect(array_intersect($firstPorts, $secondPorts))->toBe([]);
    } finally {
        foreach ($created as $name) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');
