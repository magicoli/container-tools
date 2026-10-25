<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

/** Contract for managing images, networks, and individual containers. */
interface ContainerManagerInterface
{
    /** Return whether IMAGE is available to the selected engine; connection errors also return false. */
    public function imageAvailable(string $image): bool;

    /** Pull IMAGE unless the selected engine already has it. */
    public function pull(string $image): CommandResult;

    /** Build an image from the supplied Containerfile and local build context. */
    public function build(ImageBuildDefinition $image): CommandResult;

    /** Create a network using the supplied network definition. */
    public function createNetwork(NetworkDefinition $network): CommandResult;

    /** Inspect a network by its engine-assigned name. */
    public function inspectNetwork(string $name): CommandResult;

    /** Remove a network by name. The engine may reject networks still in use. */
    public function destroyNetwork(string $name): CommandResult;

    /** Create a stopped container; image EXPOSE ports are published by default. */
    public function create(ContainerDefinition $container): CommandResult;

    /** Create one stopped container from the selected service in a Compose file. */
    public function createFromCompose(ComposeInstanceDefinition $instance): CommandResult;

    /** Start a stopped container by name or ID. */
    public function start(string $name): CommandResult;

    /** Stop a running container by name or ID. */
    public function stop(string $name): CommandResult;

    /** Inspect a container by name or ID. */
    public function inspect(string $name): CommandResult;

    /** Return lifecycle status and the container details, if inspection succeeds. */
    public function status(string $name): CommandResult;

    /**
     * Repeat PROBE inside the container until it succeeds or the timeout expires.
     *
     * @param list<string> $probe Command and arguments; no shell is implied.
     */
    public function waitUntilReady(string $name, array $probe, int $timeoutSeconds = 30, int $intervalMicroseconds = 250_000): CommandResult;

    /**
     * Run COMMAND in a running container and capture its output and exit status.
     * This does not attach an interactive terminal.
     *
     * @param non-empty-list<string> $command Command and arguments; no shell is implied.
     */
    public function exec(string $name, array $command): CommandResult;

    /** Stop if needed and remove a container by name or ID. */
    public function destroy(string $name): CommandResult;

}
