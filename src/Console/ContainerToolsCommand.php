<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Console;

use Magicoli\ContainerTools\Container\CommandResult;
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\ContainerManagerInterface;

final class ContainerToolsCommand
{
    public function __construct(private readonly ContainerManagerInterface $manager) {}

    public function run(array $arguments): int
    {
        if (in_array($arguments[0] ?? '', ['--help', '-h'], true)) {
            fwrite(STDOUT, self::usage());
            return 0;
        }

        $action = array_shift($arguments) ?? '';
        $result = match ($action) {
            'image-pull' => count($arguments) === 1 ? $this->manager->pull($arguments[0]) : null,
            'create' => $this->create($arguments),
            'start' => count($arguments) === 1 ? $this->manager->start($arguments[0]) : null,
            'stop' => count($arguments) === 1 ? $this->manager->stop($arguments[0]) : null,
            'status' => count($arguments) === 1 ? $this->manager->status($arguments[0]) : null,
            'wait-ready' => $this->ready($arguments),
            'exec' => $this->execute($arguments),
            'destroy' => count($arguments) === 1 ? $this->manager->destroy($arguments[0]) : null,
            default => null,
        };

        if (!$result instanceof CommandResult) {
            fwrite(STDERR, self::usage());
            return 2;
        }
        fwrite(STDOUT, $this->render($result));
        fwrite(STDERR, $result->stderr);

        return $result->status;
    }

    private static function usage(): string
    {
        return "usage: container-tools [--container-connection NAME] image-pull IMAGE | create [--name NAME] [--image IMAGE] [--hostname HOSTNAME] [--memory SIZE] [--label KEY=VALUE ...] [--no-start] [-- COMMAND...] | start NAME | stop NAME | status NAME | wait-ready NAME -- PROBE... | exec NAME -- COMMAND... | destroy NAME\n";
    }

    private function create(array $arguments): ?CommandResult
    {
        $name = $memory = $hostname = null;
        $image = getenv('CONTAINER_IMAGE') ?: ContainerDefinition::DEFAULT_IMAGE;
        $labels = [];
        $start = true;
        $command = [];
        while ($arguments !== []) {
            $option = array_shift($arguments);
            if ($option === '--') {
                $command = $arguments;
                break;
            }
            if ($option === '--no-start') {
                $start = false;
                continue;
            }
            $value = array_shift($arguments);
            if ($value === null) {
                return null;
            }
            match ($option) {
                '--name' => $name = $value,
                '--image' => $image = $value,
                '--hostname' => $hostname = $value,
                '--memory' => $memory = $value,
                '--label' => $labels[] = $value,
                default => null,
            };
            if (!in_array($option, ['--name', '--image', '--hostname', '--memory', '--label'], true)) {
                return null;
            }
        }
        $created = $this->manager->create(new ContainerDefinition($name, $image, $labels, $memory, $command, $hostname));
        if (!$created->succeeded() || !$start) {
            return $created;
        }
        $container = $created->data['container'] ?? [];
        $target = $name ?? $container['name'] ?? $container['id'] ?? trim($created->stdout);
        if ($target === '') {
            return new CommandResult(1, $created->stdout, $created->stderr . "Podman did not return a container name or ID\n", $created->data);
        }
        $started = $this->manager->start($target);
        $startedContainer = $started->data['container'] ?? $container;
        $summary = !$started->succeeded()
            ? 'Container created but failed to start'
            : (($startedContainer['status'] ?? null) === 'running' ? 'Container created and started' : 'Container created; image command exited');

        return new CommandResult(
            $started->status,
            $created->stdout . $started->stdout,
            $created->stderr . $started->stderr,
            [
                ...$created->data,
                'summary' => $summary,
                'container' => $startedContainer,
            ],
        );
    }

    private function ready(array $arguments): ?CommandResult
    {
        $name = array_shift($arguments);
        if ($name === null || array_shift($arguments) !== '--' || $arguments === []) {
            return null;
        }

        return $this->manager->waitUntilReady($name, $arguments);
    }

    private function execute(array $arguments): ?CommandResult
    {
        $name = array_shift($arguments);
        if ($name === null) {
            return null;
        }
        if (array_shift($arguments) !== '--' || $arguments === []) {
            return null;
        }
        return $this->manager->exec($name, $arguments);
    }

    private function render(CommandResult $result): string
    {
        if ($result->data === []) {
            return $result->stdout;
        }

        $lines = [];
        if (isset($result->data['summary'])) {
            $lines[] = $result->data['summary'];
        }
        foreach (($result->data['container'] ?? []) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $label = match ($key) {
                'id' => 'ID',
                'short_id' => 'Short ID',
                'name' => 'Name',
                'image' => 'Image',
                'hostname' => 'Hostname',
                'status' => 'Status',
                'reference' => 'Reference',
                default => ucfirst(str_replace('_', ' ', $key)),
            };
            $lines[] = "$label: " . (is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES));
        }
        foreach (['image' => 'Image', 'pulled' => 'Pulled', 'elapsed_seconds' => 'Elapsed seconds', 'exit_code' => 'Exit code'] as $key => $label) {
            if (array_key_exists($key, $result->data) && $result->data[$key] !== null) {
                $lines[] = "$label: " . (is_bool($result->data[$key]) ? ($result->data[$key] ? 'yes' : 'no') : (string) $result->data[$key]);
            }
        }
        if (isset($result->data['requested']) && is_array($result->data['requested'])) {
            foreach ($result->data['requested'] as $key => $value) {
                if ($value === null || $value === [] || $value === '') {
                    continue;
                }
                $lines[] = 'Requested ' . str_replace('_', ' ', $key) . ': ' . (is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : (string) $value);
            }
        }

        if ($result->stdout !== '' && in_array($result->data['operation'] ?? '', ['exec', 'wait-ready', 'image-pull'], true)) {
            $lines[] = rtrim($result->stdout, "\r\n");
        }

        return implode("\n", $lines) . "\n";
    }

}
