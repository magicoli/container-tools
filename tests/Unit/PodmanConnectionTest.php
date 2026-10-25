<?php

use Magicoli\ContainerTools\Container\Driver\PodmanConnection;

test('SSH connection waits until the Podman API tunnel is listening', function () {
    $directory = sys_get_temp_dir() . '/container-tools-connection-' . bin2hex(random_bytes(5));
    mkdir($directory);
    $podman = $directory . '/podman';
    $ssh = $directory . '/ssh';
    $script = PHP_BINARY;
    $previousPath = getenv('PATH');
    $previousPodman = getenv('PODMAN_BINARY');

    file_put_contents($podman, "#!" . PHP_BINARY . "\n<?php\necho json_encode([['Name' => 'delayed', 'URI' => 'ssh://podman.example.test/run/podman/podman.sock']]);\n");
    file_put_contents($ssh, <<<'PHP'
#!PHP_BINARY
<?php
$forward = null;
for ($index = 1; $index < count($argv); $index++) {
    if ($argv[$index] === '-L') {
        $forward = $argv[$index + 1] ?? null;
        break;
    }
}
if (!is_string($forward) || !preg_match('/^127\.0\.0\.1:(\d+):/', $forward, $match)) {
    exit(2);
}
usleep(300000);
$server = @stream_socket_server('tcp://127.0.0.1:' . $match[1]);
if ($server === false) {
    exit(3);
}
while ($client = @stream_socket_accept($server, 1)) {
    fclose($client);
}
PHP);
    $sshContents = str_replace('#!PHP_BINARY', '#!' . PHP_BINARY, (string) file_get_contents($ssh));
    file_put_contents($ssh, $sshContents);
    chmod($podman, 0755);
    chmod($ssh, 0755);
    putenv('PATH=' . $directory . PATH_SEPARATOR . ($previousPath === false ? '' : $previousPath));
    putenv('PODMAN_BINARY=' . $podman);

    $connection = new PodmanConnection();
    try {
        $startedAt = microtime(true);
        $endpoint = $connection->open('delayed');
        $elapsed = microtime(true) - $startedAt;
        $socket = @stream_socket_client($endpoint['compose_host'], $errorCode, $errorMessage, 1);

        expect($elapsed)->toBeGreaterThanOrEqual(0.25);
        expect(is_resource($socket))->toBeTrue("Could not connect to tunnel: $errorMessage ($errorCode)");
        if (is_resource($socket)) {
            fclose($socket);
        }
    } finally {
        $connection->close();
        $previousPath === false ? putenv('PATH') : putenv('PATH=' . $previousPath);
        $previousPodman === false ? putenv('PODMAN_BINARY') : putenv('PODMAN_BINARY=' . $previousPodman);
        unlink($podman);
        unlink($ssh);
        rmdir($directory);
    }
});
