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
        fwrite(STDOUT, $result->stdout);
        fwrite(STDERR, $result->stderr);

        return $result->status;
    }

    private static function usage(): string
    {
        return "usage: container-tools [--connection NAME] image-pull IMAGE | create [--name NAME] [--image IMAGE] [--hostname HOSTNAME] [--memory SIZE] [--label KEY=VALUE ...] [--no-start] [-- COMMAND...] | start NAME | status NAME | wait-ready NAME -- PROBE... | exec [--input] NAME -- COMMAND... | destroy NAME\n";
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
        if ($command === [] && $image === ContainerDefinition::DEFAULT_IMAGE) {
            $command = ['sh', '-c', 'while :; do sleep 3600; done'];
        }

        $created = $this->manager->create(new ContainerDefinition($name, $image, $labels, $memory, $command, $hostname));
        if (!$created->succeeded() || !$start) {
            return $created;
        }
        $target = $name ?? trim($created->stdout);
        if ($target === '') {
            return new CommandResult(1, $created->stdout, $created->stderr . "Podman did not return a container ID\n");
        }
        $started = $this->manager->start($target);

        return new CommandResult(
            $started->status,
            $created->stdout . $started->stdout,
            $created->stderr . $started->stderr,
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
        $input = ($arguments[0] ?? '') === '--input';
        $input && array_shift($arguments);
        if (array_shift($arguments) !== '--' || $arguments === []) {
            return null;
        }
        $contents = $input ? stream_get_contents(STDIN) : null;

        return $this->manager->exec($name, $arguments, $contents === false ? null : $contents);
    }

}
