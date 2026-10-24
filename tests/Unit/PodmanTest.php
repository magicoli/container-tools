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
if (in_array('inspect', $argv, true)) {
    exit(1);
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
    $calls = array_map(fn ($line) => json_decode($line, true), file($directory . '/arguments.jsonl', FILE_IGNORE_NEW_LINES));
    expect($calls[1])->toBe([
        'args' => ['create', '--name', 'sample', '--hostname', 'sample', '--label', 'test.caller=unit', 'alpine:3.22', 'sh', '-c', 'sleep 10'],
        'remote' => 'hal',
    ]);

    $ready = $podman->waitUntilReady('sample', ['sh', '-c', 'true']);
    expect($ready->status)->toBe(0);
    $calls = array_map(fn ($line) => json_decode($line, true), file($directory . '/arguments.jsonl', FILE_IGNORE_NEW_LINES));
    expect($calls[2])->toBe([
        'args' => ['exec', 'sample', 'sh', '-c', 'true'],
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
