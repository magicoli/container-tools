<?php



require_once __DIR__ . '/Support.php';

    test('CLI container readiness', function () {
    $name = integration_name('bash');
    $cli = dirname(__DIR__, 2) . '/bin/container-tools';
    $script = __DIR__ . '/podman-create-container.sh';
    $imageDirectory = dirname(__DIR__, 2) . '/examples/caddy';
    $process = proc_open(['bash', $script, $cli, $name, integration_image('alpine'), $imageDirectory . '/alpine/Containerfile', $imageDirectory, getenv('CONTAINER_CONNECTION') ?: ''], [
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
})->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');
