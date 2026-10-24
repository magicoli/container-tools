<?php

use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

function podman_stub(): string
{
    $directory = sys_get_temp_dir() . '/container-tools-' . bin2hex(random_bytes(5));
    mkdir($directory, 0755, true);
    $binary = $directory . '/podman';
    file_put_contents($binary, <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(__DIR__ . '/arguments.jsonl', json_encode(['args' => array_slice($argv, 1), 'remote' => getenv('CONTAINER_CONNECTION') ?: null], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if (($argv[1] ?? '') === 'inspect') {
    if (($argv[2] ?? '') !== '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef') {
        fwrite(STDERR, "Error: no such container\n");
        exit(1);
    }
    echo json_encode([[
        'Id' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        'Name' => '/sample',
        'ImageName' => 'alpine:3.22',
        'Config' => ['Hostname' => 'sample', 'Image' => 'alpine:3.22'],
        'State' => ['Status' => 'created'],
    ]], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'create') {
    echo "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef\n";
} elseif (($argv[1] ?? '') === 'exec') {
    echo "ready\n";
} else {
    echo "stub output\n";
}
PHP);
    chmod($binary, 0755);

    return $binary;
}

test('Podman keeps arguments separate, sets the matching hostname, and uses the explicitly selected connection', function () {
    $binary = podman_stub();
    $directory = dirname($binary);
    $podman = new Podman($binary, connection: 'hal');

    $created = $podman->create(new ContainerDefinition('sample', 'alpine:3.22', ['test.caller' => 'unit'], null, ['sh', '-c', 'sleep 10']));
    expect($created->status)->toBe(0);
    expect($created->data['container'])->toBe([
        'id' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        'short_id' => '0123456789ab',
        'name' => 'sample',
        'image' => 'alpine:3.22',
        'hostname' => 'sample',
        'status' => 'created',
    ]);
    $calls = array_map(fn ($line) => json_decode($line, true), file($directory . '/arguments.jsonl', FILE_IGNORE_NEW_LINES));
    expect($calls[1])->toBe([
        'args' => ['create', '--name', 'sample', '--hostname', 'sample', '--label', 'test.caller=unit', 'alpine:3.22', 'sh', '-c', 'sleep 10'],
        'remote' => 'hal',
    ]);

    $ready = $podman->waitUntilReady('sample', ['sh', '-c', 'true']);
    expect($ready->status)->toBe(0);
    $calls = array_map(fn ($line) => json_decode($line, true), file($directory . '/arguments.jsonl', FILE_IGNORE_NEW_LINES));
    expect($calls[2])->toBe([
        'args' => ['inspect', '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'],
        'remote' => 'hal',
    ]);
    expect($calls[3])->toBe([
        'args' => ['exec', 'sample', 'sh', '-c', 'true'],
        'remote' => 'hal',
    ]);

    foreach (['arguments.jsonl', 'podman'] as $file) {
        unlink($directory . '/' . $file);
    }
    rmdir($directory);
});

test('destroy reports an error when the requested container does not exist', function () {
    $binary = podman_stub();
    $directory = dirname($binary);
    $result = (new Podman($binary))->destroy('tenderr_mcnulty');

    expect($result->status)->toBe(1);
    expect($result->stderr)->toContain("Cannot destroy container 'tenderr_mcnulty'");
    expect($result->stderr)->toContain('Error: no such container');
    expect($result->data['operation'])->toBe('destroy');

    foreach (['arguments.jsonl', 'podman'] as $file) {
        unlink($directory . '/' . $file);
    }
    rmdir($directory);
});

test('CLI create output shows the resolved name, IDs, image, and startup result', function () {
    $binary = podman_stub();
    $directory = dirname($binary);
    $cli = dirname(__DIR__, 2) . '/bin/container-tools';
    $environment = getenv();
    $environment['PODMAN_BINARY'] = $binary;
    $process = proc_open([$cli, 'create'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    expect(is_resource($process))->toBeTrue('Could not start the CLI');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, $stderr);
    expect($output)->toContain('Container created and started');
    expect($output)->toContain('Name: sample');
    expect($output)->toContain('ID: 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
    expect($output)->toContain('Short ID: 0123456789ab');
    expect($output)->toContain('Image: alpine:3.22');

    foreach (['arguments.jsonl', 'podman'] as $file) {
        unlink($directory . '/' . $file);
    }
    rmdir($directory);
});

test('CLI forwards every native Podman argument unchanged', function () {
    $binary = podman_stub();
    $directory = dirname($binary);
    $cli = dirname(__DIR__, 2) . '/bin/container-tools';
    $environment = getenv();
    $environment['PODMAN_BINARY'] = $binary;
    $process = proc_open([$cli, '--connection', 'hal', 'podman', 'run', '--rm', '--memory', '512m', 'alpine:3.24', 'uname', '-a'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    expect(is_resource($process))->toBeTrue('Could not start the CLI');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $calls = array_map(fn ($line) => json_decode($line, true), file($directory . '/arguments.jsonl', FILE_IGNORE_NEW_LINES));

    expect($status)->toBe(0, $stderr);
    expect($output)->toBe("stub output\n");
    expect($calls[0])->toBe([
        'args' => ['run', '--rm', '--memory', '512m', 'alpine:3.24', 'uname', '-a'],
        'remote' => 'hal',
    ]);

    foreach (['arguments.jsonl', 'podman'] as $file) {
        unlink($directory . '/' . $file);
    }
    rmdir($directory);
});

test('a container definition has optional name and image defaults', function () {
    expect(fn() => new ContainerDefinition('', 'alpine:3.22', [], null, ['sh']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn() => new ContainerDefinition('sample', ''))
        ->toThrow(InvalidArgumentException::class);
    expect((new ContainerDefinition())->image)->toBe(ContainerDefinition::DEFAULT_IMAGE);
    expect((new ContainerDefinition())->name)->toBeNull();
    expect((new ContainerDefinition('sample'))->hostname)->toBeNull();
});

test('Podman honours the standard connection environment when no override is supplied', function () {
    $binary = podman_stub();
    $directory = dirname($binary);
    $previous = getenv('CONTAINER_CONNECTION');
    putenv('CONTAINER_CONNECTION=ursull');

    try {
        (new Podman($binary))->create(new ContainerDefinition('sample', 'alpine:3.22'));
    } finally {
        $previous === false ? putenv('CONTAINER_CONNECTION') : putenv('CONTAINER_CONNECTION=' . $previous);
    }

    $calls = array_map(fn ($line) => json_decode($line, true), file($directory . '/arguments.jsonl', FILE_IGNORE_NEW_LINES));
    expect($calls[1])->toBe([
        'args' => ['create', '--name', 'sample', '--hostname', 'sample', 'alpine:3.22'],
        'remote' => 'ursull',
    ]);

    foreach (['arguments.jsonl', 'podman'] as $file) {
        unlink($directory . '/' . $file);
    }
    rmdir($directory);
});
