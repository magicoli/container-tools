<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container\Driver;

use Magicoli\ContainerTools\Container\CommandResult;
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\ContainerManagerInterface;

/** Manages Podman containers without passing commands through a shell. */
final class Podman implements ContainerManagerInterface
{
    public function __construct(
        private readonly string $binary = 'podman',
        private readonly ?string $connection = null,
    ) {}

    public function imageAvailable(string $image): bool
    {
        return $this->run(['image', 'inspect', $image])->succeeded();
    }

    public function pull(string $image): CommandResult
    {
        if ($this->imageAvailable($image)) {
            return new CommandResult(0, data: [
                'operation' => 'image-pull',
                'summary' => 'Image already available',
                'image' => $image,
                'pulled' => false,
            ]);
        }

        $pulled = $this->run(['pull', $image]);
        return $pulled->withData([
            'operation' => 'image-pull',
            'summary' => $pulled->succeeded() ? 'Image pulled' : 'Image pull failed',
            'image' => $image,
            'pulled' => true,
        ]);
    }

    public function create(ContainerDefinition $container): CommandResult
    {
        $name = $container->name;
        if ($name !== null && $this->inspect($name)->succeeded()) {
            return new CommandResult(1, stderr: "Container already exists: $name\n", data: [
                'operation' => 'create',
                'summary' => 'Container already exists; nothing was created',
                'requested' => $this->requestedDefinition($container),
            ]);
        }

        $arguments = ['create'];
        if ($name !== null) {
            array_push($arguments, '--name', $name);
        }
        $hostname = $container->hostname ?? $name;
        if ($hostname !== null) {
            array_push($arguments, '--hostname', $hostname);
        }
        foreach ($container->labels as $key => $value) {
            $arguments[] = '--label';
            $arguments[] = is_int($key) ? (string) $value : "$key=$value";
        }
        if ($container->memory !== null && $container->memory !== '') {
            array_push($arguments, '--memory', $container->memory);
        }

        $created = $this->run([...$arguments, $container->image, ...$container->command]);
        if (!$created->succeeded()) {
            return $created->withData([
                'operation' => 'create',
                'summary' => 'Container creation failed',
                'requested' => $this->requestedDefinition($container),
            ]);
        }

        $id = trim($created->stdout);
        $details = $id === '' ? null : $this->containerDetails($id);
        if ($details === null) {
            return new CommandResult(1, $created->stdout, $created->stderr . "Container was created, but Podman did not return its details. ID: $id\n", [
                'operation' => 'create',
                'summary' => 'Container created; details could not be inspected',
                'id' => $id,
                'requested' => $this->requestedDefinition($container),
            ]);
        }

        return new CommandResult(0, data: [
            'operation' => 'create',
            'summary' => 'Container created',
            'container' => $details,
            'requested' => $this->requestedDefinition($container),
        ]);
    }

    public function start(string $name): CommandResult
    {
        $status = $this->status($name);
        if ($status->succeeded() && trim($status->stdout) === 'running') {
            return new CommandResult(0, data: [
                'operation' => 'start',
                'summary' => 'Container is already running',
                'container' => $this->containerDetails($name),
            ]);
        }

        $started = $this->run(['start', $name]);
        return $started->withData([
            'operation' => 'start',
            'summary' => $started->succeeded() ? 'Container started' : 'Container start failed',
            'container' => $started->succeeded() ? $this->containerDetails($name) : null,
        ]);
    }

    public function inspect(string $name): CommandResult
    {
        $result = $this->run(['inspect', $name]);
        if (!$result->succeeded()) {
            return $result->withData([
                'operation' => 'inspect',
                'summary' => 'Container inspection failed',
                'reference' => $name,
            ]);
        }

        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
            return new CommandResult(1, $result->stdout, "Podman returned an invalid inspection document for $name\n", [
                'operation' => 'inspect',
                'summary' => 'Container inspection returned invalid data',
                'reference' => $name,
            ]);
        }

        return $result->withData([
            'operation' => 'inspect',
            'summary' => 'Container details',
            'container' => $this->detailsFromInspection($decoded[0]),
        ]);
    }

    public function status(string $name): CommandResult
    {
        $inspection = $this->inspect($name);
        if (!$inspection->succeeded()) {
            return $inspection->withData([
                'operation' => 'status',
                'summary' => 'Could not read container status',
            ]);
        }
        $container = json_decode($inspection->stdout, true);
        if (!is_array($container) || !isset($container[0]['State']['Status'])) {
            return new CommandResult(1, '', "Podman returned an invalid inspection document for $name\n", [
                'operation' => 'status',
                'summary' => 'Could not read container status from Podman inspection data',
                'container' => ['reference' => $name],
            ]);
        }

        return new CommandResult(0, $container[0]['State']['Status'] . "\n", data: [
            'operation' => 'status',
            'summary' => 'Container status',
            'container' => $this->detailsFromInspection($container[0]),
        ]);
    }

    /** Run a probe until it succeeds or the readiness timeout expires. */
    public function waitUntilReady(
        string $name,
        array $probe,
        int $timeoutSeconds = 30,
        int $intervalMicroseconds = 250_000,
    ): CommandResult {
        $deadline = microtime(true) + $timeoutSeconds;
        $startedAt = microtime(true);
        do {
            $result = $this->exec($name, $probe);
            if ($result->succeeded()) {
                return $result->withData([
                    'operation' => 'wait-ready',
                    'summary' => 'Container readiness probe passed',
                    'container' => $this->containerDetails($name) ?? ['reference' => $name],
                    'probe' => $probe,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
                ]);
            }
            if (microtime(true) >= $deadline) {
                return $result->withData([
                    'operation' => 'wait-ready',
                    'summary' => 'Container readiness probe timed out',
                    'container' => $this->containerDetails($name) ?? ['reference' => $name],
                    'probe' => $probe,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
                ]);
            }
            usleep($intervalMicroseconds);
        } while (true);
    }

    public function exec(string $name, array $command, ?string $input = null): CommandResult
    {
        $result = $this->run(['exec', ...($input === null ? [] : ['-i']), $name, ...$command], $input);
        return $result->withData([
            'operation' => 'exec',
            'summary' => $result->succeeded() ? 'Command executed in container' : 'Command failed in container',
            'container' => ['reference' => $name],
            'exit_code' => $result->status,
        ]);
    }

    public function destroy(string $name): CommandResult
    {
        $inspection = $this->inspect($name);
        $details = $inspection->data['container'] ?? null;
        if (!$inspection->succeeded() || !is_array($details)) {
            return new CommandResult($inspection->status, '', "Cannot destroy container '$name' because Podman could not inspect it.\n" . $inspection->stderr, [
                'operation' => 'destroy',
                'summary' => 'Container was not destroyed because it could not be inspected',
                'container' => ['reference' => $name],
            ]);
        }

        $destroyed = $this->run(['rm', '--force', $name]);
        return $destroyed->withData([
            'operation' => 'destroy',
            'summary' => $destroyed->succeeded() ? 'Container destroyed' : 'Container destruction failed',
            'container' => $details,
        ]);
    }

    public function run(array $arguments, ?string $input = null): CommandResult
    {
        $process = @proc_open($this->command($arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->environment());
        if (!is_resource($process)) {
            return new CommandResult(127, '', "Could not start {$this->binary}\n");
        }

        if ($input !== null && $input !== '') {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        while (true) {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if ($read === []) {
                break;
            }
            $write = $except = [];
            if (stream_select($read, $write, $except, 0, 100_000) === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new CommandResult(proc_close($process), $stdout, $stderr);
    }

    private function command(array $arguments): array
    {
        return [$this->binary, ...$arguments];
    }

    private function environment(): ?array
    {
        if ($this->connection === null || $this->connection === '') {
            return null;
        }

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['CONTAINER_CONNECTION'] = $this->connection;

        return $environment;
    }

    private function requestedDefinition(ContainerDefinition $container): array
    {
        return [
            'name' => $container->name,
            'image' => $container->image,
            'hostname' => $container->hostname ?? $container->name,
            'labels' => $container->labels,
            'memory' => $container->memory,
            'command' => $container->command,
        ];
    }

    private function containerDetails(string $reference): ?array
    {
        $inspection = $this->inspect($reference);
        return $inspection->succeeded() ? ($inspection->data['container'] ?? null) : null;
    }

    private function detailsFromInspection(array $container): array
    {
        $id = $container['Id'] ?? null;
        $name = $container['Name'] ?? null;
        return [
            'id' => $id,
            'short_id' => is_string($id) ? substr($id, 0, 12) : null,
            'name' => is_string($name) ? ltrim($name, '/') : null,
            'image' => $container['ImageName'] ?? $container['Config']['Image'] ?? null,
            'hostname' => $container['Config']['Hostname'] ?? null,
            'status' => $container['State']['Status'] ?? null,
        ];
    }
}
