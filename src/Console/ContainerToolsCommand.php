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
            'running' => count($arguments) === 1 ? $this->manager->running($arguments[0]) : null,
            'ready' => $this->ready($arguments),
            'exec' => $this->execute($arguments),
            'remove' => $this->remove($arguments),
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
        return "usage: container-tools [--remote NAME] image-pull IMAGE | create --name NAME --image IMAGE [--memory SIZE] [--label KEY=VALUE ...] [--start] [-- COMMAND...] | start NAME | running NAME | ready NAME -- PROBE... | exec [--input] NAME -- COMMAND... | remove [--force] NAME\n";
    }

    private function create(array $arguments): ?CommandResult
    {
        $name = $image = $memory = null;
        $labels = [];
        $start = false;
        $command = [];
        while ($arguments !== []) {
            $option = array_shift($arguments);
            if ($option === '--') {
                $command = $arguments;
                break;
            }
            if ($option === '--start') {
                $start = true;
                continue;
            }
            $value = array_shift($arguments);
            if ($value === null) {
                return null;
            }
            match ($option) {
                '--name' => $name = $value,
                '--image' => $image = $value,
                '--memory' => $memory = $value,
                '--label' => $labels[] = $value,
                default => null,
            };
            if (!in_array($option, ['--name', '--image', '--memory', '--label'], true)) {
                return null;
            }
        }
        if ($name === null || $image === null) {
            return null;
        }

        $created = $this->manager->create(new ContainerDefinition($name, $image, $labels, $memory, $command));
        if (!$created->succeeded() || !$start) {
            return $created;
        }
        $started = $this->manager->start($name);

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

    private function remove(array $arguments): ?CommandResult
    {
        $force = ($arguments[0] ?? '') === '--force';
        $force && array_shift($arguments);

        return count($arguments) === 1 ? $this->manager->remove($arguments[0], $force) : null;
    }
}
