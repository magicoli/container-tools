<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container\Module\Compose;

use Magicoli\ContainerTools\Container\CommandResult;

/** Runs the Compose provider selected by Podman's standard `podman compose` command. */
final class PodmanCompose
{
    public function __construct(private readonly ?string $connection = null) {}

    public function up(string $file, string $service, string $project, ?string $containerName): CommandResult
    {
        $file = realpath($file) ?: $file;
        if (!is_file($file)) {
            return new CommandResult(2, stderr: "Compose file not found: $file\n");
        }

        $command = [getenv('PODMAN_BINARY') ?: 'podman'];
        if ($this->connection !== null && $this->connection !== '') {
            array_push($command, '--connection', $this->connection);
        }
        array_push($command, 'compose', '--file', $file, '--project-name', $project, 'up', '--detach', '--no-start', $service);

        return $this->run($command, dirname($file), $containerName);
    }

    private function run(array $command, string $workingDirectory, ?string $containerName): CommandResult
    {
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        if ($containerName !== null) {
            $environment['CONTAINER_TOOLS_CONTAINER_NAME'] = $containerName;
        }

        $process = @proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory, $environment);
        if (!is_resource($process)) {
            return new CommandResult(127, stderr: "Could not start the Podman Compose command.\n");
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return new CommandResult($status, (string) $stdout, (string) $stderr);
    }
}
