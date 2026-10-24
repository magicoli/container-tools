<?php

use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;
use Magicoli\ContainerTools\Container\ImageBuildDefinition;

function integration_podman(): Podman
{
    return new Podman();
}

function integration_name(string $caller): string
{
    $host = preg_replace('/[^a-z0-9-]+/', '-', strtolower(gethostname() ?: 'host'));

    return 'container-tools-test-' . trim((string) $host, '-') . '-' . $caller . '-' . bin2hex(random_bytes(4));
}

function integration_image(): string
{
    return 'localhost/container-tools-test:latest';
}

function build_integration_image(Podman $podman): void
{
    $directory = __DIR__ . '/image';
    $result = $podman->build(new ImageBuildDefinition(
        $directory . '/Containerfile',
        integration_image(),
        $directory,
        'docker.io/library/caddy:2-alpine',
    ));
    expect($result->status)->toBe(0, $result->stderr . $result->stdout);
}

describe('Podman integration', function () {
    test('PHP creates, starts, and checks readiness of a container', function () {
        $podman = integration_podman();
        $name = integration_name('php');
        $created = false;

        try {
            build_integration_image($podman);
            $result = $podman->create(new ContainerDefinition($name, integration_image(), ['test.caller' => 'php']));
            expect($result->status)->toBe(0, $result->stderr);
            $created = true;
            expect($result->data['container']['name'])->toBe($name);
            expect($result->data['container']['id'])->toHaveLength(64);
            expect($result->data['container']['image'])->toBe(integration_image());
            expect($result->data['container']['api']['Config']['OpenStdin'])->toBeTrue();
            expect($result->data['container']['api']['Config']['Tty'])->toBeTrue();
            expect(array_keys($result->data['container']['api']['Config']['ExposedPorts']))->toContain('22/tcp', '80/tcp', '443/tcp');
            expect($podman->start($name)->status)->toBe(0);
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
    });

    test('Bash creates, starts, and checks readiness through the command adapter', function () {
        $name = integration_name('bash');
        $cli = dirname(__DIR__, 2) . '/bin/container-tools';
        $script = __DIR__ . '/podman-create-container.sh';
        $imageDirectory = __DIR__ . '/image';
        $process = proc_open(['bash', $script, $cli, $name, integration_image(), $imageDirectory . '/Containerfile', $imageDirectory, getenv('CONTAINER_CONNECTION') ?: ''], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        expect(is_resource($process))->toBeTrue('Could not start the Bash integration script');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        expect($status)->toBe(0, $stderr);
        expect((string) $stdout)->toContain('Name: ' . $name);
        expect((string) $stdout)->toContain('Status: running');
    });
});
