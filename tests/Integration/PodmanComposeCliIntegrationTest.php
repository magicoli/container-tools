<?php


require_once __DIR__ . '/Support.php';

test('CLI Compose instance', function () {
    $podman = integration_podman();
    $name = integration_name('compose-cli');
    $created = false;

    try {
        $created = true;
        $cli = dirname(__DIR__, 2) . '/bin/container-tools';
        $configuration = __DIR__ . '/../fixtures/compose-instance/compose.yaml';
        $process = proc_open([
            $cli,
            'create',
            '--name=' . $name,
            '--compose-file=' . $configuration,
            '--service=instance',
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        expect(is_resource($process))->toBeTrue('Could not start the container-tools CLI');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        expect($status)->toBe(0, $stderr . $stdout);
        expect($stdout)->toContain('Name: ' . $name);
        expect($stdout)->toContain('Status: running');
        expect($podman->exec($name, ['test', '-d', '/var/lib/container-tools-instance'])->status)->toBe(0);
    } finally {
        if ($created) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason('compose') !== null, integration_skip_reason('compose') ?? '');
