<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container\Driver;

use RuntimeException;

/** Resolves Podman's standard connection settings to a local REST endpoint. */
final class PodmanConnection
{
    private mixed $tunnel = null;

    /** @return array{base_uri: string, curl: array, connection: ?string} */
    public function open(?string $connection = null): array
    {
        $connection ??= (getenv('CONTAINER_CONNECTION') ?: null);
        $host = getenv('CONTAINER_HOST') ?: (getenv('DOCKER_HOST') ?: null);
        $socketOptions = [];
        $isMachine = false;

        if ($host !== null) {
            $uri = $host;
        } else {
            $entry = $this->configuredConnection($connection);
            $uri = $entry['URI'] ?? null;
            if (!is_string($uri) || $uri === '') {
                $uri = $this->defaultSocket();
            }
            if (isset($entry['Identity']) && is_string($entry['Identity']) && $entry['Identity'] !== '') {
                $socketOptions['identity'] = $entry['Identity'];
            }
            $isMachine = ($entry['IsMachine'] ?? false) === true;
        }

        if (str_starts_with($uri, 'unix://')) {
            return [
                'base_uri' => 'http://localhost/',
                'curl' => [CURLOPT_UNIX_SOCKET_PATH => substr($uri, strlen('unix://'))],
                'connection' => $connection,
            ];
        }

        if (str_starts_with($uri, 'ssh://')) {
            $parts = parse_url($uri);
            if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
                throw new RuntimeException("Invalid Podman SSH connection URI: $uri");
            }
            $port = $this->availablePort();
            $target = (isset($parts['user']) ? $parts['user'] . '@' : '') . $parts['host'];
            $arguments = ['ssh', '-N', '-p', (string) ($parts['port'] ?? 22), '-L', "127.0.0.1:$port:" . $parts['path'], '-o', 'ExitOnForwardFailure=yes', '-o', 'BatchMode=yes'];
            if (isset($socketOptions['identity'])) {
                array_push($arguments, '-i', $socketOptions['identity']);
            }
            if ($isMachine) {
                array_push($arguments, '-o', 'StrictHostKeyChecking=no', '-o', 'UserKnownHostsFile=/dev/null');
            }
            $arguments[] = $target;
            $this->tunnel = @proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($this->tunnel)) {
                throw new RuntimeException('Could not start the SSH tunnel for the Podman REST API.');
            }
            return [
                'base_uri' => "http://127.0.0.1:$port/",
                'curl' => [],
                'connection' => $connection,
            ];
        }

        if (str_starts_with($uri, 'tcp://')) {
            $uri = 'http://' . substr($uri, strlen('tcp://'));
        }
        return ['base_uri' => rtrim($uri, '/') . '/', 'curl' => [], 'connection' => $connection];
    }

    public function close(): void
    {
        if (is_resource($this->tunnel)) {
            proc_terminate($this->tunnel);
            proc_close($this->tunnel);
            $this->tunnel = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function configuredConnection(?string $requested): array
    {
        $process = @proc_open([getenv('PODMAN_BINARY') ?: 'podman', 'system', 'connection', 'list', '--format', 'json'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return [];
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $connections = $status === 0 ? json_decode($stdout, true) : null;
        if (!is_array($connections)) {
            return [];
        }

        foreach ($connections as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($requested !== null && ($entry['Name'] ?? null) === $requested) || ($requested === null && ($entry['Default'] ?? false))) {
                return $entry;
            }
        }

        if ($requested !== null) {
            throw new RuntimeException("Podman connection '$requested' was not found.");
        }

        return [];
    }

    private function defaultSocket(): string
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $candidates = ["/run/user/$uid/podman/podman.sock", '/run/podman/podman.sock', '/var/run/docker.sock'];
        foreach ($candidates as $socket) {
            if (file_exists($socket)) {
                return 'unix://' . $socket;
            }
        }
        throw new RuntimeException('No Podman REST socket found. Set CONTAINER_HOST or configure a Podman connection.');
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException("Could not allocate a local port for the Podman API tunnel: $error");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr(strrchr((string) $address, ':'), 1);
    }
}
