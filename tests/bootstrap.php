<?php

declare(strict_types=1);

/**
 * Load shell-style project and test configuration while preserving values
 * supplied by the caller (for example `CONTAINER_CONNECTION=ursull pest`).
 *
 * Files are sourced in order: project `.env`, then `tests/.env`. The shell
 * source lets the test environment accept any Podman-supported variable.
 */
function container_tools_load_test_environment(string $projectRoot): void
{
    $callerEnvironment = getenv();
    if (!is_array($callerEnvironment)) {
        $callerEnvironment = [];
    }

    $script = <<<'BASH'
set -a
for file in "$@"; do
    if [[ -f "$file" ]]; then
        source "$file" >/dev/null || exit $?
    fi
done
env -0
BASH;

    $process = @proc_open(
        [getenv('BASH_BINARY') ?: 'bash', '-c', $script, 'container-tools-test-environment', $projectRoot . '/.env', $projectRoot . '/tests/.env'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Bash to load tests/.env and the project .env.');
    }

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('Could not load test environment files: ' . trim((string) $error));
    }

    foreach (explode("\0", (string) $output) as $entry) {
        if (!str_contains($entry, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $entry, 2);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)
            || array_key_exists($name, $callerEnvironment)
            || in_array($name, ['SHLVL', '_'], true)) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

container_tools_load_test_environment(dirname(__DIR__));

if (getenv('CONTAINER_CONNECTION')) {
    fwrite(STDERR, 'tests: Podman connection: ' . getenv('CONTAINER_CONNECTION') . PHP_EOL);
}
