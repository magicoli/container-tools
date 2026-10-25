<?php

test('test env precedence', function () {
    $directory = sys_get_temp_dir() . '/container-tools-env-' . bin2hex(random_bytes(6));
    mkdir($directory . '/tests', 0700, true);
    $projectKey = 'CONTAINER_TOOLS_ENV_PROJECT_TEST';
    $overrideKey = 'CONTAINER_TOOLS_ENV_OVERRIDE_TEST';
    $callerKey = 'CONTAINER_TOOLS_ENV_CALLER_TEST';
    $keys = [$projectKey, $overrideKey, $callerKey];
    $previous = array_map(static fn (string $key) => getenv($key), $keys);

    file_put_contents($directory . '/.env', "export $projectKey=project\nexport $overrideKey=project\nexport $callerKey=project\n");
    file_put_contents($directory . '/tests/.env', "export $overrideKey=tests\nexport $callerKey=tests\n");
    putenv($callerKey . '=caller');

    try {
        container_tools_load_test_environment($directory);

        expect(getenv($projectKey))->toBe('project');
        expect(getenv($overrideKey))->toBe('tests');
        expect(getenv($callerKey))->toBe('caller');
    } finally {
        foreach ($keys as $index => $key) {
            $previous[$index] === false ? putenv($key) : putenv($key . '=' . $previous[$index]);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        unlink($directory . '/tests/.env');
        unlink($directory . '/.env');
        rmdir($directory . '/tests');
        rmdir($directory);
    }
});
