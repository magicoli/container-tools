<?php

use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

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
    return getenv('PODMAN_TEST_IMAGE') ?: getenv('CONTAINER_IMAGE') ?: 'docker.io/library/nginx:alpine';
}

describe('Podman integration', function () {
    test('PHP creates, starts, and checks readiness of a container', function () {
        $podman = integration_podman();
        $name = integration_name('php');
        $created = false;

        try {
            $pulled = $podman->pull(integration_image());
            expect($pulled->status)->toBe(0, $pulled->stderr);
            // Keep the image's default foreground server command, as a server
            // container should live for as long as its service process does.
            $result = $podman->create(new ContainerDefinition($name, integration_image(), ['test.caller' => 'php']));
            expect($result->status)->toBe(0, $result->stderr);
            expect($result->data['container']['name'])->toBe($name);
            expect($result->data['container']['id'])->toHaveLength(64);
            expect($result->data['container']['image'])->toBe(integration_image());
            expect($result->data['container']['api']['Config']['OpenStdin'])->toBeTrue();
            expect($result->data['container']['api']['Config']['Tty'])->toBeTrue();
            $created = true;
            expect($podman->start($name)->status)->toBe(0);
            expect($podman->waitUntilReady($name, ['wget', '-q', '-O', '/dev/null', 'http://127.0.0.1/'])->status)->toBe(0);
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
        $process = proc_open(['bash', $script, $cli, $name, integration_image(), getenv('CONTAINER_CONNECTION') ?: ''], [
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
