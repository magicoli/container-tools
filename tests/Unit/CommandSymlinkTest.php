<?php

test('command finds the Composer autoloader through a symlink', function () {
    $directory = sys_get_temp_dir() . '/container-tools-command-' . bin2hex(random_bytes(5));
    $fakeBin = $directory . '/bin';
    mkdir($fakeBin, 0777, true);
    $command = $directory . '/container-tools';
    $fakePhp = $fakeBin . '/php';
    $realCommand = dirname(__DIR__, 2) . '/bin/container-tools';

    symlink($realCommand, $command);
    file_put_contents($fakePhp, <<<'SH'
#!/bin/sh
test -r "$CONTAINER_TOOLS_AUTOLOAD" || exit 70
printf '%s\n' "$CONTAINER_TOOLS_AUTOLOAD"
SH);
    chmod($fakePhp, 0755);

    $environment = getenv();
    $environment['PATH'] = $fakeBin . PATH_SEPARATOR . ($environment['PATH'] ?? '');
    $process = proc_open(
        ['/bin/bash', $command, '--help'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $environment,
    );

    try {
        expect(is_resource($process))->toBeTrue('Could not start the symlinked command.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        expect($status)->toBe(0, $stderr);
        expect(realpath(trim($stdout)))->toBe(realpath(dirname(__DIR__, 2) . '/vendor/autoload.php'));
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        unlink($command);
        unlink($fakePhp);
        rmdir($fakeBin);
        rmdir($directory);
    }
});
