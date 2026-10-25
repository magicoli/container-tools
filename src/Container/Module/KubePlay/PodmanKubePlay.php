<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container\Module\KubePlay;

use Magicoli\ContainerTools\Container\CommandResult;

/** Runs Podman's kube play/kube down against the selected local or remote engine. */
final class PodmanKubePlay
{
    public function __construct(private readonly ?string $connection = null) {}

    public function play(KubePlayDefinition $definition): CommandResult
    {
        return $this->run($definition, false);
    }

    public function down(KubePlayDefinition $definition): CommandResult
    {
        return $this->run($definition, true);
    }

    private function run(KubePlayDefinition $definition, bool $down): CommandResult
    {
        $file = realpath($definition->file);
        if ($file === false || !is_file($file) || !is_readable($file)) {
            return new CommandResult(2, stderr: "Kubernetes manifest not found or unreadable: {$definition->file}\n", data: [
                'operation' => $down ? 'kube-down' : 'kube-play',
                'manifest' => $definition->file,
                'networks' => $definition->networks,
            ]);
        }
        $manifest = file_get_contents($file);
        if ($manifest === false) {
            return new CommandResult(1, stderr: "Could not read Kubernetes manifest: $file\n", data: [
                'operation' => $down ? 'kube-down' : 'kube-play',
                'manifest' => $file,
                'networks' => $definition->networks,
            ]);
        }

        $command = [getenv('PODMAN_BINARY') ?: 'podman'];
        $connection = $this->connection ?? (getenv('CONTAINER_CONNECTION') ?: null);
        if ($connection !== null && $connection !== '') {
            array_push($command, '--connection', $connection);
        }
        array_push($command, 'kube', 'play');
        if ($down) {
            $command[] = '--down';
        }
        if ($definition->replace && !$down) {
            $command[] = '--replace';
        }
        foreach ($definition->networks as $network) {
            array_push($command, '--network', $network);
        }
        $command[] = '-';

        $process = @proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname($file));
        if (!is_resource($process)) {
            return new CommandResult(127, stderr: "Could not start the Podman kube play command.\n", data: [
                'operation' => $down ? 'kube-down' : 'kube-play',
                'manifest' => $file,
                'networks' => $definition->networks,
            ]);
        }

        fwrite($pipes[0], $manifest);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return new CommandResult($status, (string) $stdout, (string) $stderr, [
            'operation' => $down ? 'kube-down' : 'kube-play',
            'summary' => $status === 0
                ? ($down ? 'Pod deployment removed' : 'Pod deployment created')
                : ($down ? 'Pod deployment removal failed' : 'Pod deployment failed'),
            'manifest' => $file,
            'networks' => $definition->networks,
            'replace' => $definition->replace && !$down,
            'connection' => $connection,
        ]);
    }
}
