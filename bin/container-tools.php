#!/usr/bin/env php
<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/autoload.php';
}
require $autoload;

use Magicoli\ContainerTools\Container\CommandResult;
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

$arguments = array_slice($_SERVER['argv'], 1);
$connection = getenv('CONTAINER_CONNECTION') ?: null;
$binary = getenv('PODMAN_BINARY') ?: 'podman';
if (($arguments[0] ?? '') === '--connection') {
    array_shift($arguments);
    $connection = array_shift($arguments) ?: null;
}

$podman = new Podman($binary, $connection);
$action = array_shift($arguments) ?? '';
$result = match ($action) {
    'image-pull' => count($arguments) === 1 ? $podman->pull($arguments[0]) : null,
    'create' => create($podman, $arguments),
    'start' => count($arguments) === 1 ? $podman->start($arguments[0]) : null,
    'running' => count($arguments) === 1 ? $podman->running($arguments[0]) : null,
    'ready' => ready($podman, $arguments),
    'exec' => execute($podman, $arguments),
    'remove' => remove($podman, $arguments),
    default => null,
};

if (!$result instanceof CommandResult) {
    fwrite(STDERR, "usage: container-tools [--connection NAME] image-pull IMAGE | create --name NAME --image IMAGE [--memory SIZE] [--label KEY=VALUE ...] -- COMMAND... | start NAME | running NAME | ready NAME -- PROBE... | exec [--input] NAME -- COMMAND... | remove [--force] NAME\n");
    exit(2);
}

fwrite(STDOUT, $result->stdout);
fwrite(STDERR, $result->stderr);
exit($result->status);

function create(Podman $podman, array $arguments): ?CommandResult
{
    $name = $image = $memory = null;
    $labels = [];
    while ($arguments !== []) {
        $option = array_shift($arguments);
        if ($option === '--') {
            break;
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

    return $name !== null && $image !== null && $arguments !== []
        ? $podman->create(new ContainerDefinition($name, $image, $labels, $memory, $arguments))
        : null;
}

function ready(Podman $podman, array $arguments): ?CommandResult
{
    $name = array_shift($arguments);
    if ($name === null || array_shift($arguments) !== '--' || $arguments === []) {
        return null;
    }

    return $podman->waitUntilReady($name, $arguments);
}

function execute(Podman $podman, array $arguments): ?CommandResult
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

    return $podman->exec($name, $arguments, $contents === false ? null : $contents);
}

function remove(Podman $podman, array $arguments): ?CommandResult
{
    $force = ($arguments[0] ?? '') === '--force';
    $force && array_shift($arguments);
    return count($arguments) === 1 ? $podman->remove($arguments[0], $force) : null;
}
