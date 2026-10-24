# Container Tools

A small PHP library for Podman container lifecycle operations, with a command adapter for shell callers.

The first milestone covers image availability, container creation and startup, state inspection, readiness probes, command execution, and destruction. It does not install or configure applications inside containers.

The public API is under `Magicoli\ContainerTools\Container`: `ContainerManagerInterface` defines the driver contract, `ContainerDefinition` describes a container, and `Container\Driver\Podman` implements the contract. The command in `bin/` is a thin shell-facing adapter over that API.

## Quick start

The image and name are optional. The default image is Alpine 3.24; with that image and no command, the tool keeps a shell alive so the container is useful after creation. The CLI starts containers by default:

```bash
./bin/container-tools create --name example
./bin/container-tools status example
./bin/container-tools destroy example
```

Podman assigns a readable random name if `--name` is omitted. When a name is provided, the container's hostname defaults to that name. `--hostname HOSTNAME` overrides it. Podman itself uses the container ID as the hostname when it generates the name; keeping Podman's generated name while making the hostname match it would require generating the name in this library instead.

Use `--image IMAGE` or `CONTAINER_IMAGE=IMAGE` to choose another default image. Use `--no-start` only when a stopped container is specifically needed. `--` separates this tool's options from the command to run inside the container:

```bash
./bin/container-tools create --name example --image docker.io/library/alpine:3.24 -- sh -c 'while :; do sleep 3600; done'
```

## Commands

- `status NAME` reports Podman's lifecycle state (`created`, `running`, `exited`, and so on).
- `wait-ready NAME -- PROBE...` runs the probe repeatedly until it succeeds or times out. A running container is not necessarily ready to serve requests, so this is useful when a service needs startup time.
- `start NAME` starts an existing stopped container.
- `destroy NAME` removes a container, stopping it first if needed.
- `image-pull IMAGE` downloads an image explicitly. Creating a container also uses Podman's normal image pull behavior.

## PHP

The library keeps the lifecycle operations explicit so PHP callers can decide when to create, start, and wait for readiness:

```php
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

$podman = new Podman(connection: getenv('CONTAINER_CONNECTION') ?: null);
$podman->create(new ContainerDefinition(name: 'example', command: [
    'sh', '-c', 'while :; do sleep 3600; done',
]));
$podman->start('example');
$podman->waitUntilReady('example', ['sh', '-c', 'test -r /etc/os-release']);
```

## Bash

`bin/container-tools` is a Bash wrapper around the PSR-4 command in `src/Console`. `--connection NAME` selects a configured Podman connection; otherwise the standard Podman `CONTAINER_CONNECTION` setting is inherited. `PODMAN_BINARY` can select a non-default Podman executable.

## Alpine and OpenSim

Alpine is suitable as a lightweight generic test image, but it is not yet a supported OpenSim installation target. Microsoft currently lists .NET 8, 9, and 10 on Alpine 3.21–3.23, and Alpine provides `libgdiplus`; however, OpenSimulator's own Linux instructions name the .NET runtime and `libgdiplus` without documenting Alpine. This project currently installs OpenSim through Debian packages and APT. Alpine would need a separate installation path and end-to-end validation before it could be called supported.

## Tests

`composer test` runs unit tests and real Podman integration tests. Podman and PHP are required; integration tests create uniquely named containers and remove them afterward. Use Podman’s standard `CONTAINER_CONNECTION` variable or pass `--connection NAME` to select a configured Podman connection, and set `PODMAN_TEST_IMAGE` to override the integration image.
