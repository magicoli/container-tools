# Container Tools

A small PHP library for Podman container lifecycle operations, with a command adapter for shell callers.

The first milestone covers image builds from a local Containerfile and build context, image availability, container creation and startup, state inspection, readiness probes, command execution, stopping, and destruction. Container operations use Podman's official Docker-compatible REST API; Guzzle handles HTTP and cURL handles the Unix socket transport. Podman's configured SSH connection is used only to establish an SSH tunnel to its API socket.

The public API is under `Magicoli\ContainerTools\Container`: `ContainerManagerInterface` defines the driver contract, `ContainerDefinition` describes a container, and `Container\Driver\Podman` implements the contract. The command in `bin/` is a thin shell-facing adapter over that API.

Each operation returns a `CommandResult` with `status`, `stdout`, `stderr`, and structured `data`. For example, `create()` returns the effective container `id`, `short_id`, Podman-assigned `name`, image, hostname, and initial state, as well as the requested definition. `destroy()` includes the details collected before removal; if Podman cannot inspect the requested name or ID, it fails without deleting anything. The CLI prints these fields as a short readable summary, and returns a nonzero exit status on failure.

## Quick start

The image and name are optional. The default image is Alpine 3.24. The CLI pulls the image if it is missing, then starts the container without replacing the image's configured command. A container remains active only while that command remains active: a minimal Alpine or Debian image may exit, while an Nginx image runs its server in the foreground:

Creation defaults to keeping stdin open and allocating a TTY, matching Podman Desktop's defaults. This allows images whose default command is an interactive shell, such as Debian's `bash`, to remain running after the create request returns.

```bash
./bin/container-tools image-pull docker.io/library/nginx:alpine
./bin/container-tools create --name example --image docker.io/library/nginx:alpine
./bin/container-tools status example
./bin/container-tools wait-ready example -- wget -q -O /dev/null http://127.0.0.1/
./bin/container-tools stop example
./bin/container-tools destroy example
```

The global options follow the shared shell-tool conventions: `-v|--verbose` prints the complete, formatted REST inspection document, `-q|--quiet` suppresses normal output, `-h|--help` prints usage, and `-d|--dry-run` prints the requested command without performing it. They can appear before or after the action. Options with values accept both `--image IMAGE` and `--image=IMAGE` forms.

Podman assigns a readable random name if `--name` is omitted; the result includes that final name and both full and short IDs. When a name is provided, the container's hostname defaults to that name. `--hostname HOSTNAME` overrides it. For an automatically assigned name, Podman chooses its hostname at creation time, so the hostname may be the container ID rather than the friendly name.

Use `--image IMAGE` or `CONTAINER_IMAGE=IMAGE` to choose another default image. Use `--no-start` only when a stopped container is specifically needed. `--` separates this tool's options from the command to run inside the container. The `CommandResult` includes the full REST inspection document under `data.container.api`, including the image's actual command and entrypoint.

```bash
./bin/container-tools create --name example --image docker.io/library/nginx:alpine
```

## Commands

- `build --file FILE --tag IMAGE [--from BASE_IMAGE] [--no-cache] [CONTEXT]` builds and tags an image using a Containerfile. The context defaults to the Containerfile's directory. `--from` selects the base image for the first `FROM`; `--no-cache` disables layer cache for this build. Build and create remain separate operations: create accepts an image reference and never builds implicitly. The PHP host needs `tar` to package the context for the Podman API.
- `status NAME` reports Podman's lifecycle state (`created`, `running`, `exited`, and so on).
- `wait-ready NAME -- PROBE...` runs the probe repeatedly until it succeeds or times out. A running container is not necessarily ready to serve requests, so this is useful when a service needs startup time.
- `start NAME` starts an existing stopped container; `stop NAME` stops a running container.
- `destroy NAME` removes a container, stopping it first if needed.
- `image-pull IMAGE` downloads an image explicitly before creation.

## PHP

The library keeps the lifecycle operations explicit so PHP callers can decide when to create, start, and wait for readiness:

```php
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;
use Magicoli\ContainerTools\Container\ImageBuildDefinition;

$podman = new Podman(); // Honors CONTAINER_HOST or Podman's CONTAINER_CONNECTION.
$podman->build(new ImageBuildDefinition(
    file: __DIR__ . '/Containerfile',
    tag: 'localhost/example:latest',
    context: __DIR__,
    from: 'docker.io/library/debian:12-slim',
));
$podman->pull('docker.io/library/nginx:alpine');
$podman->create(new ContainerDefinition(name: 'example', image: 'docker.io/library/nginx:alpine'));
$podman->start('example');
$podman->waitUntilReady('example', ['wget', '-q', '-O', '/dev/null', 'http://127.0.0.1/']);
$podman->exec('example', ['php', '--version']);
$podman->stop('example');
$podman->destroy('example');
```

## Bash

`bin/container-tools` is a Bash wrapper around the PSR-4 command in `src/Console`. `--container-connection NAME` and `--container-connection=NAME` select a configured Podman connection; otherwise the standard Podman `CONTAINER_CONNECTION` setting is inherited. `CONTAINER_HOST` can point directly to a Unix socket or HTTP endpoint.

## Build and run a test image

The integration fixtures include Caddy and OpenSSH variants based on Debian 12 and Alpine. Build the Debian 12 variant, then create and inspect a container from it:

```bash
./bin/container-tools build \
  --file=examples/caddy/debian12/Containerfile \
  --tag=localhost/caddy-sshd:debian12 \
  examples/caddy
./bin/container-tools create --name=debian-caddy --image=localhost/caddy-sshd:debian12
./bin/container-tools status debian-caddy
./bin/container-tools wait-ready debian-caddy -- wget -q -O /dev/null http://127.0.0.1/
```

The Alpine recipe is at `examples/caddy/alpine/Containerfile`. Both examples share the Caddyfile and entrypoint in `examples/caddy/`. `EXPOSE` records the ports inside the image; it does not publish ports on the host.

## Tests

`composer test` runs unit tests and real Podman integration tests. Podman, PHP with cURL, and `tar` are required. The integration suite builds the Debian 12 and Alpine Caddy/OpenSSH examples, creates uniquely named containers, exercises their lifecycle and readiness probes, then removes the containers. Use Podman’s standard `CONTAINER_CONNECTION` variable or pass `--container-connection NAME` to select a configured Podman connection.
