<?php

use Magicoli\ContainerTools\Container\Module\Compose\PodmanCompose;

test('Compose passes connection and name', function () {
    $directory = sys_get_temp_dir() . '/container-tools-compose-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $binary = $directory . '/podman';
    $composeFile = $directory . '/compose.yaml';
    file_put_contents($composeFile, "services:\n  web:\n    image: caddy:alpine\n");
    $composeFile = realpath($composeFile);
    file_put_contents($binary, <<<'SH'
#!/bin/sh
printf '%s\n' "$@" > "$CONTAINER_TOOLS_TEST_ARGS"
printf '%s' "$CONTAINER_TOOLS_CONTAINER_NAME" > "$CONTAINER_TOOLS_TEST_NAME"
printf 'compose provider completed\n'
SH);
    chmod($binary, 0700);

    $previous = [];
    foreach (['PODMAN_BINARY', 'CONTAINER_TOOLS_TEST_ARGS', 'CONTAINER_TOOLS_TEST_NAME'] as $key) {
        $previous[$key] = getenv($key);
    }
    putenv('PODMAN_BINARY=' . $binary);
    putenv('CONTAINER_TOOLS_TEST_ARGS=' . $directory . '/args');
    putenv('CONTAINER_TOOLS_TEST_NAME=' . $directory . '/name');

    try {
        $result = (new PodmanCompose('ursull'))->up($composeFile, 'web', 'project-1', 'web-instance');

        expect($result->status)->toBe(0);
        expect($result->stdout)->toContain('compose provider completed');
        expect(file_get_contents($directory . '/args'))->toBe("--connection\nursull\ncompose\n--file\n$composeFile\n--project-name\nproject-1\nup\n--detach\n--no-start\nweb\n");
        expect(file_get_contents($directory . '/name'))->toBe('web-instance');
    } finally {
        foreach ($previous as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
        foreach (glob($directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});
