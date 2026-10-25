<?php

use Magicoli\ContainerTools\Container\Module\KubePlay\KubePlayDefinition;
use Magicoli\ContainerTools\Container\Module\KubePlay\PodmanKubePlay;

function fake_podman_for_kube_play(): array
{
    $directory = sys_get_temp_dir() . '/container-tools-kube-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $binary = $directory . '/podman';
    file_put_contents($binary, <<<'SH'
#!/bin/sh
printf '%s\n' "$@" > "$CONTAINER_TOOLS_TEST_ARGS"
cat > "$CONTAINER_TOOLS_TEST_STDIN"
printf 'podman kube fixture completed\n'
SH);
    chmod($binary, 0700);

    return [$directory, $binary];
}

function with_fake_podman_environment(string $binary, string $directory, callable $callback): mixed
{
    $previous = [];
    foreach (['PODMAN_BINARY', 'CONTAINER_CONNECTION', 'CONTAINER_TOOLS_TEST_ARGS', 'CONTAINER_TOOLS_TEST_STDIN'] as $key) {
        $previous[$key] = getenv($key);
    }
    putenv('PODMAN_BINARY=' . $binary);
    putenv('CONTAINER_TOOLS_TEST_ARGS=' . $directory . '/args');
    putenv('CONTAINER_TOOLS_TEST_STDIN=' . $directory . '/stdin');
    try {
        return $callback();
    } finally {
        foreach ($previous as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
    }
}

function remove_fake_podman_directory(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
}

test('Kube Play uses selected connection', function () {
    [$directory, $binary] = fake_podman_for_kube_play();
    $manifest = dirname(__DIR__) . '/fixtures/kube-play/alpine.yaml';

    try {
        with_fake_podman_environment($binary, $directory, function () use ($directory, $manifest) {
            $result = (new PodmanKubePlay('hal'))->play(new KubePlayDefinition($manifest, ['podman'], replace: true));

            expect($result->status)->toBe(0);
            expect($result->stdout)->toContain('podman kube fixture completed');
            expect(file_get_contents($directory . '/args'))
                ->toBe("--connection\nhal\nkube\nplay\n--replace\n--network\npodman\n-\n");
            expect(file_get_contents($directory . '/stdin'))->toBe(file_get_contents($manifest));
            expect($result->data)->toMatchArray([
                'operation' => 'kube-play',
                'summary' => 'Pod deployment created',
                'networks' => ['podman'],
                'replace' => true,
                'connection' => 'hal',
            ]);
        });
    } finally {
        remove_fake_podman_directory($directory);
    }
});

test('Kube Play down uses selected connection', function () {
    [$directory, $binary] = fake_podman_for_kube_play();
    $manifest = dirname(__DIR__) . '/fixtures/kube-play/alpine.yaml';

    try {
        with_fake_podman_environment($binary, $directory, function () use ($directory, $manifest) {
            $connection = getenv('CONTAINER_CONNECTION') ?: null;
            $result = (new PodmanKubePlay())->down(new KubePlayDefinition($manifest));

            expect($result->status)->toBe(0);
            expect(file_get_contents($directory . '/args'))->toBe(
                ($connection === null ? '' : "--connection\n$connection\n") . "kube\nplay\n--down\n-\n",
            );
            expect($result->data['operation'])->toBe('kube-down');
            expect($result->data['connection'])->toBe($connection);
            expect($result->data['networks'])->toBe([]);
        });
    } finally {
        remove_fake_podman_directory($directory);
    }
});

test('Kube Play rejects missing manifest', function () {
    [$directory, $binary] = fake_podman_for_kube_play();

    try {
        with_fake_podman_environment($binary, $directory, function () {
            $result = (new PodmanKubePlay())->play(new KubePlayDefinition('/missing/container-tools.yaml'));

            expect($result->status)->toBe(2);
            expect($result->stderr)->toContain('not found or unreadable');
        });
        expect(file_exists($directory . '/args'))->toBeFalse();
    } finally {
        remove_fake_podman_directory($directory);
    }
});
