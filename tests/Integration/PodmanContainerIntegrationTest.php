<?php

use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\PortForward;

require_once __DIR__ . '/Support.php';

    test('PHP container readiness', function () {
    $podman = integration_podman();
    $name = integration_name('php');
    $created = false;

    try {
        build_integration_image($podman, 'debian12');
        $result = $podman->create(new ContainerDefinition(
            $name,
            integration_image('debian12'),
            ['test.caller' => 'php'],
            portForwards: [new PortForward(null, 80, hostIp: '127.0.0.1')],
        ));
        expect($result->status)->toBe(0, $result->stderr);
        $created = true;
        expect($result->data['container']['name'])->toBe($name);
        expect($result->data['container']['id'])->toHaveLength(64);
        expect($result->data['container']['image'])->toBe(integration_image('debian12'));
        expect($result->data['container']['api']['Config']['OpenStdin'])->toBeTrue();
        expect($result->data['container']['api']['Config']['Tty'])->toBeTrue();
        expect(array_keys($result->data['container']['api']['Config']['ExposedPorts']))->toContain('22/tcp', '80/tcp', '443/tcp');
        $started = $podman->start($name);
        expect($started->status)->toBe(0);
        expect($started->data['container']['ports'])->toHaveCount(3);
        $httpPort = array_values(array_filter($started->data['container']['ports'], static fn (array $port) => $port['container'] === '80/tcp'))[0] ?? [];
        expect($httpPort)->toMatchArray([
            'container' => '80/tcp',
            'host_ip' => '127.0.0.1',
        ]);
        expect($httpPort['host_port'] ?? '')->not->toBe('');
        $probe = ['sh', '-c', 'wget -q -O /dev/null http://127.0.0.1/ && cat /proc/net/tcp /proc/net/tcp6 | grep -q ":0016 " && cat /proc/net/tcp /proc/net/tcp6 | grep -q ":0050 " && cat /proc/net/tcp /proc/net/tcp6 | grep -q ":01BB "'];
        $ready = $podman->waitUntilReady($name, $probe);
        expect($ready->status)->toBe(0, $ready->stderr . $ready->stdout);
        expect(trim($podman->exec($name, ['wget', '-q', '-O', '-', 'http://127.0.0.1/'])->stdout))->toBe('container-tools test image');
        expect(trim($podman->status($name)->stdout))->toBe('running');
        expect(trim($podman->exec($name, ['hostname'])->stdout))->toBe($name);
        expect($podman->stop($name)->status)->toBe(0);
        expect(trim($podman->status($name)->stdout))->toBe('exited');
        expect($podman->start($name)->status)->toBe(0);
    } finally {
        if ($created) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');
