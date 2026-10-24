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
file_put_contents(__DIR__ . '/arguments.jsonl', json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
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

test('Podman keeps arguments separate and applies the remote to every command', function () {
    $binary = podman_stub();
    $directory = dirname($binary);
    $podman = new Podman($binary, remote: 'hal');

    $created = $podman->create(new ContainerDefinition('sample', 'alpine:3.22', ['test.caller' => 'unit'], null, ['sh', '-c', 'sleep 10']));
    expect($created->status)->toBe(0);
    $calls = array_map('json_decode', file($directory . '/arguments.jsonl', FILE_IGNORE_NEW_LINES));
    expect($calls[1])->toBe([
        '--connection', 'hal', 'create', '--name', 'sample', '--label', 'test.caller=unit', 'alpine:3.22', 'sh', '-c', 'sleep 10',
    ]);

    $ready = $podman->waitUntilReady('sample', ['sh', '-c', 'true']);
    expect($ready->status)->toBe(0);
    $calls = array_map('json_decode', file($directory . '/arguments.jsonl', FILE_IGNORE_NEW_LINES));
    expect($calls[2])->toBe([
        '--connection', 'hal', 'exec', 'sample', 'sh', '-c', 'true',
    ]);

    foreach (['arguments.jsonl', 'podman'] as $file) {
        unlink($directory . '/' . $file);
    }
    rmdir($directory);
});

test('a container definition requires a name and image but may use the image command', function () {
    expect(fn() => new ContainerDefinition('', 'alpine:3.22', [], null, ['sh']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn() => new ContainerDefinition('sample', ''))
        ->toThrow(InvalidArgumentException::class);
    expect((new ContainerDefinition('sample', 'alpine:3.22'))->command)->toBe([]);
});
