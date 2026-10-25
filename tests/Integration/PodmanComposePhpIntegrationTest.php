<?php

use Magicoli\ContainerTools\Container\ComposeInstanceDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

require_once __DIR__ . '/Support.php';

test('PHP Compose instance', function () {
    $podman = integration_podman();
    $name = integration_name('compose');
    $created = false;

    try {
        $configuration = __DIR__ . '/../fixtures/compose-instance/compose.yaml';
        $created = true;
        $result = $podman->createFromCompose(new ComposeInstanceDefinition($configuration, 'instance', $name));
        expect($result->status)->toBe(0, $result->stderr . $result->stdout);
        $container = $result->data['container'];
        expect($container['name'])->toBe($name);
        expect($container['status'])->toBe('created');
        expect($container['api']['Config']['Image'])->toBe('docker.io/library/alpine:3.24');
        expect($container['api']['Config']['Env'])->toContain('CONTAINER_TOOLS_COMPOSE_TEST=enabled');
        expect($container['api']['Config']['Labels'])->toMatchArray(['test.configuration' => 'compose']);
        $instanceMount = array_values(array_filter(
            $container['api']['Mounts'],
            static fn (array $mount): bool => ($mount['Destination'] ?? null) === '/var/lib/container-tools-instance',
        ));
        expect($instanceMount)->toHaveCount(1);
        expect($instanceMount[0]['Type'])->toBe('volume');
        expect($container['api']['NetworkSettings']['Ports']['8080/tcp'][0]['HostIp'])->toBe('127.0.0.1');
        expect($container['api']['NetworkSettings']['Ports']['8080/tcp'][0]['HostPort'])->not->toBe('');
        expect($podman->start($name)->status)->toBe(0);
        expect(trim($podman->status($name)->stdout))->toBe('running');
        expect($podman->exec($name, ['test', '-d', '/var/lib/container-tools-instance'])->status)->toBe(0);
    } finally {
        if ($created) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason('compose') !== null, integration_skip_reason('compose') ?? '');
