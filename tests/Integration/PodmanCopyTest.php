<?php

use Magicoli\ContainerTools\Container\ContainerDefinition;

require_once __DIR__ . '/Support.php';

describe('container file transfer', function () {
    test('PHP copies a file into a stopped container', function () {
        $podman = integration_podman();
        $name = integration_name('copy-api');
        $source = tempnam(sys_get_temp_dir(), 'container-tools-copy-');
        file_put_contents($source, "copied by PHP\n");
        $created = false;

        try {
            $result = $podman->create(new ContainerDefinition(
                $name,
                'docker.io/library/alpine:3.24',
                command: ['sh', '-c', 'sleep 300'],
            ));
            expect($result->status)->toBe(0, $result->stderr . $result->stdout);
            $created = true;

            $copied = $podman->copyTo($name, $source, '/tmp/copied.txt');
            expect($copied->status)->toBe(0, $copied->stderr . $copied->stdout);
            expect($podman->start($name)->status)->toBe(0);
            $read = $podman->exec($name, ['cat', '/tmp/copied.txt']);
            expect($read->status)->toBe(0, $read->stderr);
            expect($read->stdout)->toBe("copied by PHP\n");
        } finally {
            if ($created) {
                $podman->destroy($name);
            }
            unlink($source);
        }
    })->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');

    test('CLI copies a file into a running container', function () {
        $podman = integration_podman();
        $name = integration_name('copy-cli');
        $source = tempnam(sys_get_temp_dir(), 'container-tools-copy-');
        file_put_contents($source, "copied by CLI\n");
        $created = false;

        try {
            $result = $podman->create(new ContainerDefinition(
                $name,
                'docker.io/library/alpine:3.24',
                command: ['sh', '-c', 'sleep 300'],
            ));
            expect($result->status)->toBe(0, $result->stderr . $result->stdout);
            $created = true;
            expect($podman->start($name)->status)->toBe(0);

            $cli = dirname(__DIR__, 2) . '/bin/container-tools';
            $process = proc_open([$cli, 'cp', $source, $name . ':/tmp/copied.txt'], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            expect(is_resource($process))->toBeTrue('Could not start container-tools CLI');
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            expect($status)->toBe(0, $stderr . $stdout);
            expect($stdout)->toContain('Files copied to container');

            $read = $podman->exec($name, ['cat', '/tmp/copied.txt']);
            expect($read->status)->toBe(0, $read->stderr);
            expect($read->stdout)->toBe("copied by CLI\n");
        } finally {
            if ($created) {
                $podman->destroy($name);
            }
            unlink($source);
        }
    })->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');
});
