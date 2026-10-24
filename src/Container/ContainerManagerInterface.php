<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

interface ContainerManagerInterface
{
    public function imageAvailable(string $image): bool;

    public function pull(string $image): CommandResult;

    public function build(ImageBuildDefinition $image): CommandResult;

    public function create(ContainerDefinition $container): CommandResult;

    public function start(string $name): CommandResult;

    public function stop(string $name): CommandResult;

    public function inspect(string $name): CommandResult;

    public function status(string $name): CommandResult;

    public function waitUntilReady(string $name, array $probe, int $timeoutSeconds = 30, int $intervalMicroseconds = 250_000): CommandResult;

    public function exec(string $name, array $command): CommandResult;

    public function destroy(string $name): CommandResult;

}
