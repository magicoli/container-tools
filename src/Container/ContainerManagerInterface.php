<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

interface ContainerManagerInterface
{
    public function imageAvailable(string $image): bool;

    public function pull(string $image): CommandResult;

    public function create(ContainerDefinition $container): CommandResult;

    public function start(string $name): CommandResult;

    public function inspect(string $name): CommandResult;

    public function running(string $name): CommandResult;

    public function waitUntilReady(string $name, array $probe, int $timeoutSeconds = 30, int $intervalMicroseconds = 250_000): CommandResult;

    public function exec(string $name, array $command, ?string $input = null): CommandResult;

    public function remove(string $name, bool $force = false): CommandResult;
}
