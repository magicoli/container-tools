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
            return new CommandResult(0, "Image already available: $image\n");
        }

        return $this->run(['pull', $image]);
    }

    public function create(ContainerDefinition $container): CommandResult
    {
        $name = $container->name;
        if ($name !== null && $this->inspect($name)->succeeded()) {
            return new CommandResult(0, "Container already exists: $name\n");
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

        return $this->run([...$arguments, $container->image, ...$container->command]);
    }

    public function start(string $name): CommandResult
    {
        $status = $this->status($name);
        if ($status->succeeded() && trim($status->stdout) === 'running') {
            return new CommandResult(0, "Container already running: $name\n");
        }

        return $this->run(['start', $name]);
    }

    public function inspect(string $name): CommandResult
    {
        return $this->run(['inspect', $name]);
    }

    public function status(string $name): CommandResult
    {
        $inspection = $this->inspect($name);
        if (!$inspection->succeeded()) {
            return $inspection;
        }
        $container = json_decode($inspection->stdout, true);
        if (!is_array($container) || !isset($container[0]['State']['Status'])) {
            return new CommandResult(1, '', "Podman returned an invalid inspection document for $name\n");
        }

        return new CommandResult(0, $container[0]['State']['Status'] . "\n");
    }

    /** Run a probe until it succeeds or the readiness timeout expires. */
    public function waitUntilReady(
        string $name,
        array $probe,
        int $timeoutSeconds = 30,
        int $intervalMicroseconds = 250_000,
    ): CommandResult {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $result = $this->exec($name, $probe);
            if ($result->succeeded()) {
                return $result;
            }
            if (microtime(true) >= $deadline) {
                return $result;
            }
            usleep($intervalMicroseconds);
        } while (true);
    }

    public function exec(string $name, array $command, ?string $input = null): CommandResult
    {
        return $this->run(['exec', ...($input === null ? [] : ['-i']), $name, ...$command], $input);
    }

    public function destroy(string $name): CommandResult
    {
        return $this->run(['rm', '--force', $name]);
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
}
