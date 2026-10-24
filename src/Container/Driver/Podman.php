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
        if ($this->inspect($name)->succeeded()) {
            return new CommandResult(0, "Container already exists: $name\n");
        }

        $arguments = ['create', '--name', $name];
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
        $running = $this->running($name);
        if ($running->succeeded() && trim($running->stdout) === 'true') {
            return new CommandResult(0, "Container already running: $name\n");
        }

        return $this->run(['start', $name]);
    }

    public function inspect(string $name): CommandResult
    {
        return $this->run(['inspect', $name]);
    }

    public function running(string $name): CommandResult
    {
        $inspection = $this->inspect($name);
        if (!$inspection->succeeded()) {
            return $inspection;
        }
        $container = json_decode($inspection->stdout, true);
        if (!is_array($container) || !isset($container[0]['State']['Running'])) {
            return new CommandResult(1, '', "Podman returned an invalid inspection document for $name\n");
        }

        return new CommandResult(0, $container[0]['State']['Running'] ? "true\n" : "false\n");
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

    public function remove(string $name, bool $force = false): CommandResult
    {
        return $this->run(['rm', ...($force ? ['--force'] : []), $name]);
    }

    public function run(array $arguments, ?string $input = null): CommandResult
    {
        $process = @proc_open($this->command($arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
        $command = [$this->binary];
        if ($this->connection !== null && $this->connection !== '') {
            array_push($command, '--connection', $this->connection);
        }

        return [...$command, ...$arguments];
    }
}
