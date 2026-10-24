<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Magicoli\ContainerTools\Container\CommandResult;
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\ContainerManagerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Podman adapter using its official Docker-compatible REST API. */
final class Podman implements ContainerManagerInterface
{
    private readonly PodmanConnection $connection;
    private readonly ClientInterface $http;
    private readonly string $apiVersion;

    public function __construct(?string $connection = null, ?ClientInterface $httpClient = null)
    {
        if ($httpClient !== null) {
            $this->connection = new PodmanConnection();
            $this->http = $httpClient;
        } else {
            if (!extension_loaded('curl')) {
                throw new \RuntimeException('The PHP curl extension is required to connect to the Podman REST API.');
            }
            $this->connection = new PodmanConnection();
            $endpoint = $this->connection->open($connection);
            $this->http = new Client([
                'base_uri' => $endpoint['base_uri'],
                'http_errors' => false,
                'connect_timeout' => 10,
                'timeout' => 0,
                'curl' => $endpoint['curl'],
            ]);
        }
        $this->apiVersion = $this->negotiateApiVersion();
    }

    public function imageAvailable(string $image): bool
    {
        return $this->request('GET', $this->path('images/' . rawurlencode($image) . '/json'))->succeeded();
    }

    public function pull(string $image): CommandResult
    {
        if ($this->imageAvailable($image)) {
            return new CommandResult(0, data: ['operation' => 'image-pull', 'summary' => 'Image already available', 'image' => $image, 'pulled' => false]);
        }
        $result = $this->request('POST', $this->path('images/create') . '?fromImage=' . rawurlencode($image));
        return $result->withData(['operation' => 'image-pull', 'summary' => $result->succeeded() ? 'Image pulled' : 'Image pull failed', 'image' => $image, 'pulled' => true]);
    }

    public function create(ContainerDefinition $container): CommandResult
    {
        $image = $this->pull($container->image);
        if (!$image->succeeded()) {
            return $image->withData([
                'operation' => 'create',
                'summary' => 'Container creation failed because its image could not be obtained',
                'requested' => $this->requestedDefinition($container),
            ]);
        }

        $query = $container->name === null ? '' : '?name=' . rawurlencode($container->name);
        $config = ['Image' => $container->image];
        $hostname = $container->hostname ?? $container->name;
        if ($hostname !== null) {
            $config['Hostname'] = $hostname;
        }
        if ($container->labels !== []) {
            $config['Labels'] = $this->labels($container->labels);
        }
        if ($container->command !== []) {
            $config['Cmd'] = $container->command;
        }
        if ($container->memory !== null && $container->memory !== '') {
            $config['HostConfig'] = ['Memory' => $this->memoryBytes($container->memory)];
        }

        $result = $this->request('POST', $this->path('containers/create') . $query, $config);
        if (!$result->succeeded()) {
            return $result->withData(['operation' => 'create', 'summary' => 'Container creation failed', 'requested' => $this->requestedDefinition($container)]);
        }

        $created = json_decode($result->stdout, true);
        $id = is_array($created) ? ($created['Id'] ?? null) : null;
        if (!is_string($id) || $id === '') {
            return new CommandResult(1, $result->stdout, "Podman returned no container ID after creation.\n", ['operation' => 'create', 'summary' => 'Container was created but its ID was missing']);
        }
        $inspection = $this->inspect($id);
        if (!$inspection->succeeded()) {
            return $inspection->withData(['operation' => 'create', 'summary' => 'Container was created but could not be inspected', 'id' => $id]);
        }
        return new CommandResult(0, $result->stdout, $result->stderr, [
            'operation' => 'create',
            'summary' => 'Container created',
            'container' => $inspection->data['container'],
            'requested' => $this->requestedDefinition($container),
            'image_pulled' => $image->data['pulled'] ?? false,
        ]);
    }

    public function start(string $name): CommandResult
    {
        $result = $this->request('POST', $this->path('containers/' . rawurlencode($name) . '/start'));
        return $this->lifecycleResult($result, 'start', 'Container started', $name);
    }

    public function stop(string $name): CommandResult
    {
        $result = $this->request('POST', $this->path('containers/' . rawurlencode($name) . '/stop'));
        return $this->lifecycleResult($result, 'stop', 'Container stopped', $name);
    }

    public function inspect(string $name): CommandResult
    {
        $result = $this->request('GET', $this->path('containers/' . rawurlencode($name) . '/json'));
        if (!$result->succeeded()) {
            return $result->withData(['operation' => 'inspect', 'summary' => 'Container inspection failed', 'reference' => $name]);
        }
        $details = json_decode($result->stdout, true);
        if (!is_array($details)) {
            return new CommandResult(1, $result->stdout, "Podman returned invalid container inspection data.\n", ['operation' => 'inspect', 'reference' => $name]);
        }
        return $result->withData(['operation' => 'inspect', 'summary' => 'Container details', 'container' => $this->details($details)]);
    }

    public function status(string $name): CommandResult
    {
        $inspection = $this->inspect($name);
        if (!$inspection->succeeded()) {
            return $inspection->withData(['operation' => 'status', 'summary' => 'Could not read container status']);
        }
        $status = $inspection->data['container']['status'] ?? 'unknown';
        return new CommandResult(0, $status . "\n", data: ['operation' => 'status', 'summary' => 'Container status', 'container' => $inspection->data['container']]);
    }

    public function waitUntilReady(string $name, array $probe, int $timeoutSeconds = 30, int $intervalMicroseconds = 250_000): CommandResult
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $startedAt = microtime(true);
        do {
            $result = $this->exec($name, $probe);
            if ($result->succeeded()) {
                return $result->withData(['operation' => 'wait-ready', 'summary' => 'Container readiness probe passed', 'probe' => $probe, 'elapsed_seconds' => round(microtime(true) - $startedAt, 3)]);
            }
            if (microtime(true) >= $deadline) {
                return $result->withData(['operation' => 'wait-ready', 'summary' => 'Container readiness probe timed out', 'probe' => $probe, 'elapsed_seconds' => round(microtime(true) - $startedAt, 3)]);
            }
            usleep($intervalMicroseconds);
        } while (true);
    }

    public function exec(string $name, array $command): CommandResult
    {
        $created = $this->request('POST', $this->path('containers/' . rawurlencode($name) . '/exec'), [
            'AttachStdin' => false,
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Tty' => false,
            'Cmd' => array_values($command),
        ]);
        if (!$created->succeeded()) {
            return $created->withData(['operation' => 'exec', 'container' => ['reference' => $name]]);
        }
        $execId = json_decode($created->stdout, true)['Id'] ?? null;
        if (!is_string($execId) || $execId === '') {
            return new CommandResult(1, $created->stdout, "Podman returned no exec session ID.\n", ['operation' => 'exec']);
        }
        $started = $this->request('POST', $this->path('exec/' . rawurlencode($execId) . '/start'), ['Detach' => false, 'Tty' => false]);
        if (!$started->succeeded()) {
            return $started->withData(['operation' => 'exec', 'container' => ['reference' => $name]]);
        }
        [$stdout, $stderr] = $this->demultiplex($started->stdout);
        $inspection = $this->request('GET', $this->path('exec/' . rawurlencode($execId) . '/json'));
        $exitCode = json_decode($inspection->stdout, true)['ExitCode'] ?? 0;
        $exitCode = is_int($exitCode) ? $exitCode : 0;
        return new CommandResult($exitCode, $stdout, $stderr, ['operation' => 'exec', 'summary' => $exitCode === 0 ? 'Command executed in container' : 'Command failed in container', 'container' => ['reference' => $name], 'exit_code' => $exitCode]);
    }

    public function destroy(string $name): CommandResult
    {
        $inspection = $this->inspect($name);
        if (!$inspection->succeeded()) {
            return $inspection->withData(['operation' => 'destroy', 'summary' => 'Container was not destroyed because it could not be inspected']);
        }
        $result = $this->request('DELETE', $this->path('containers/' . rawurlencode($name) . '?force=true'));
        return $result->withData(['operation' => 'destroy', 'summary' => $result->succeeded() ? 'Container destroyed' : 'Container destruction failed', 'container' => $inspection->data['container']]);
    }

    private function request(string $method, string $path, ?array $json = null): CommandResult
    {
        try {
            $options = [];
            if ($json !== null) {
                $options['json'] = $json;
            }
            $response = $this->http->request($method, $path, $options);
            return $this->responseResult($response);
        } catch (GuzzleException|Throwable $exception) {
            return new CommandResult(1, '', $exception->getMessage() . "\n");
        }
    }

    private function responseResult(ResponseInterface $response): CommandResult
    {
        $body = (string) $response->getBody();
        $status = $response->getStatusCode();
        if (($status >= 200 && $status < 300) || $status === 304) {
            return new CommandResult(0, $body);
        }
        $document = json_decode($body, true);
        $message = is_array($document) ? ($document['message'] ?? $document['cause'][0]['message'] ?? null) : null;
        return new CommandResult($status, '', (is_string($message) ? $message : trim($body ?: $response->getReasonPhrase())) . "\n", ['http_status' => $status]);
    }

    private function negotiateApiVersion(): string
    {
        $response = null;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $response = $this->request('GET', 'version');
            if ($response->succeeded()) {
                break;
            }
            usleep(100_000);
        }
        if (!$response->succeeded()) {
            throw new \RuntimeException('Could not connect to the Podman REST API: ' . trim($response->stderr));
        }
        $version = json_decode($response->stdout, true);
        $maximum = min('1.44', (string) ($version['ApiVersion'] ?? '1.40'));
        $minimum = (string) ($version['MinAPIVersion'] ?? '1.24');
        if (version_compare($maximum, $minimum, '<')) {
            throw new \RuntimeException('The container engine exposes no compatible Docker API version.');
        }
        return $maximum;
    }

    private function path(string $path): string
    {
        return 'v' . $this->apiVersion . '/' . ltrim($path, '/');
    }

    private function lifecycleResult(CommandResult $result, string $operation, string $success, string $name): CommandResult
    {
        return $result->withData(['operation' => $operation, 'summary' => $result->succeeded() ? $success : "Container $operation failed", 'container' => $this->detailsAfter($name)]);
    }

    private function detailsAfter(string $name): ?array
    {
        $inspection = $this->inspect($name);
        return $inspection->succeeded() ? ($inspection->data['container'] ?? null) : null;
    }

    private function details(array $container): array
    {
        $id = $container['Id'] ?? null;
        return [
            'id' => $id,
            'short_id' => is_string($id) ? substr($id, 0, 12) : null,
            'name' => isset($container['Name']) ? ltrim((string) $container['Name'], '/') : null,
            'image' => $container['Config']['Image'] ?? null,
            'hostname' => $container['Config']['Hostname'] ?? null,
            'status' => $container['State']['Status'] ?? null,
            'api' => $container,
        ];
    }

    private function requestedDefinition(ContainerDefinition $container): array
    {
        return ['name' => $container->name, 'image' => $container->image, 'hostname' => $container->hostname ?? $container->name, 'labels' => $container->labels, 'memory' => $container->memory, 'command' => $container->command];
    }

    private function memoryBytes(string $memory): int
    {
        if (!preg_match('/^(\d+)([kmg]?)b?$/i', trim($memory), $parts)) {
            throw new \InvalidArgumentException("Invalid container memory value: $memory");
        }
        $power = match (strtolower($parts[2])) { 'k' => 1, 'm' => 2, 'g' => 3, default => 0 };
        return (int) $parts[1] * (1024 ** $power);
    }

    private function labels(array $labels): array
    {
        $normalized = [];
        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                [$key, $value] = array_pad(explode('=', (string) $value, 2), 2, '');
            }
            $normalized[(string) $key] = (string) $value;
        }
        return $normalized;
    }

    /** Decode Docker's multiplexed stdout/stderr stream. */
    private function demultiplex(string $stream): array
    {
        $stdout = $stderr = '';
        $offset = 0;
        $length = strlen($stream);
        while ($offset + 8 <= $length) {
            $header = unpack('Cstream/C3reserved/Nlength', substr($stream, $offset, 8));
            $offset += 8;
            $chunkLength = $header['length'];
            $chunk = substr($stream, $offset, $chunkLength);
            $offset += $chunkLength;
            if ($header['stream'] === 2) {
                $stderr .= $chunk;
            } else {
                $stdout .= $chunk;
            }
        }
        if ($offset === 0) {
            return [$stream, ''];
        }
        return [$stdout, $stderr];
    }
}
