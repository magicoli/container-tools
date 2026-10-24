<?php

use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

function integration_podman(): Podman
{
    return new Podman(getenv('PODMAN_BINARY') ?: 'podman', getenv('CONTAINER_REMOTE') ?: null);
}

function integration_name(string $caller): string
{
    $host = preg_replace('/[^a-z0-9-]+/', '-', strtolower(gethostname() ?: 'host'));

    return 'container-tools-test-' . trim((string) $host, '-') . '-' . $caller . '-' . bin2hex(random_bytes(4));
}

function integration_image(): string
{
    return getenv('PODMAN_TEST_IMAGE') ?: 'docker.io/library/alpine:3.22';
}

describe('Podman integration', function () {
    test('PHP creates, starts, and checks readiness of a container', function () {
        $podman = integration_podman();
        $name = integration_name('php');
        $created = false;

        try {
            $pulled = $podman->pull(integration_image());
            expect($pulled->status)->toBe(0, $pulled->stderr);
            $result = $podman->create(new ContainerDefinition($name, integration_image(), ['test.caller' => 'php'], null, [
                'sh', '-c', 'while :; do sleep 3600; done',
            ]));
            expect($result->status)->toBe(0, $result->stderr);
            $created = true;
            expect($podman->start($name)->status)->toBe(0);
            expect($podman->waitUntilReady($name, ['sh', '-c', 'test -r /etc/os-release'])->status)->toBe(0);
            expect(trim($podman->running($name)->stdout))->toBe('true');
        } finally {
            if ($created) {
                $podman->remove($name, true);
            }
        }
    });

    test('Bash creates, starts, and checks readiness through the command adapter', function () {
        $name = integration_name('bash');
        $cli = dirname(__DIR__, 2) . '/bin/container-tools';
        $script = __DIR__ . '/podman-create-container.sh';
        $process = proc_open(['bash', $script, $cli, $name, integration_image(), getenv('CONTAINER_REMOTE') ?: ''], [
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
        expect(trim((string) $stdout))->toEndWith('true');
    });
});
