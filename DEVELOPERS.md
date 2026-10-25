# Developer and integration guide

This guide is for contributors to `magicoli/container-tools` and developers integrating it into another application.

The complete PHP method and result contracts are in [docs/php-api.md](docs/php-api.md). Keep that reference and public PHPDoc synchronized with implementation changes.

## What this package provides

`container-tools` is an independent, framework-agnostic PHP library and shell command adapter for managing Podman containers. It is the host-side integration point for applications that need to create and control containers without installing a domain package on the machine running the installer or administrator command.

An installer, administrator CLI, or service manager can call this package from its own host while selecting a local or remote Podman host. The target does not need the consuming application's other runtime libraries installed just for the host to manage its containers.

The package provides two entry points over the same container-management domain:

- **PHP API** for applications that need typed definitions, structured results, and direct lifecycle calls.
- **`container-tools` command** for shell scripts, installers, and terminal workflows. It is a supported integration surface, not merely a demo of the PHP API.

The host-side command path is useful when the caller is a shell installer or terminal-facing CLI: it invokes `container-tools` from its own process and targets the selected Podman host.

Both currently use Podman. Compose and Kube Play are separate Podman modules because their resource lifecycles differ from individual containers. Do not add a second container runtime or deployment format by duplicating existing transport and lifecycle logic; extend the relevant driver or module boundary.

## Add the dependency

Add `magicoli/container-tools` to the consuming project's Composer dependencies. Its executable is exposed as `vendor/bin/container-tools` by Composer. The library requires PHP 8.2+, `ext-curl`, and Guzzle 7.9+; image builds also require `tar` on the PHP host.

Use the Composer autoloader for PHP integration:

```php
require __DIR__ . '/vendor/autoload.php';

use Magicoli\ContainerTools\Container\ContainerManagerInterface;
use Magicoli\ContainerTools\Container\Driver\Podman;

$containers = new Podman();
```

Prefer depending on `ContainerManagerInterface` in application services and injecting a driver at the composition root. Keep application-specific naming, configuration, policy, and orchestration in the consuming application; keep Podman REST/API mechanics in this package.

For shell integration, invoke the Composer-installed command:

```bash
vendor/bin/container-tools --help
vendor/bin/container-tools create --name app-service --image docker.io/library/alpine:3.24
vendor/bin/container-tools status app-service
```

The CLI honors the standard `-v|--verbose`, `-q|--quiet`, `-h|--help`, and `-d|--dry-run` options and accepts option values as either `--option value` or `--option=value`. Its `exec NAME -- COMMAND...` runs a command in a running container and reports its exit status. This command adapter is usable from an interactive or non-interactive host terminal; the current `exec` operation itself does not attach an interactive PTY.

## Select the Podman host

The library honors Podman's `CONTAINER_CONNECTION` convention and the standard host variables resolved by Podman, including `CONTAINER_HOST` and `DOCKER_HOST`. The CLI also accepts `-c|--connection NAME` (including `--connection=NAME`). An explicit CLI option takes precedence for that invocation. A PHP caller can pass a connection name to the driver:

```php
$containers = new Podman(connection: $connectionName);
```

Do not put a developer's connection name, hostname, socket path, or credentials in source, examples, tests, or defaults. Do not clear or replace a caller's environment. The consuming application owns configuration loading: this library does not automatically read `.env`. Load the caller's configuration first, then pass the selected connection or leave the standard Podman environment intact.

Podman remote connections use Podman's configured connection information to reach the Podman REST API. Container operations execute on the selected Podman host, but build-context paths and Compose files originate on the client host and are sent/processed according to their respective module behavior. Check each operation's input-path semantics before assuming a local path exists on the remote host.

## Use the PHP API

Lifecycle calls are explicit and return `CommandResult`. A successful container `create()` creates the container but does not start it; call `start()` separately. Check each result before relying on its data:

```php
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\ImageBuildDefinition;

$built = $containers->build(new ImageBuildDefinition(
    file: __DIR__ . '/Containerfile',
    tag: 'localhost/example:latest',
    context: __DIR__,
));
if (!$built->succeeded()) {
    throw new RuntimeException($built->stderr);
}

$created = $containers->create(new ContainerDefinition(
    name: 'example',
    image: 'localhost/example:latest',
));
if (!$created->succeeded()) {
    throw new RuntimeException($created->stderr);
}

$name = $created->data['container']['name'];
$started = $containers->start($name);
if (!$started->succeeded()) {
    throw new RuntimeException($started->stderr);
}

$ready = $containers->waitUntilReady($name, ['wget', '-q', '-O', '/dev/null', 'http://127.0.0.1/']);
if (!$ready->succeeded()) {
    throw new RuntimeException($ready->stderr);
}
```

Use the returned structured `data` for IDs, assigned names, effective ports, lifecycle state, and operation summaries. Do not parse the human-readable CLI output or raw Podman JSON when the corresponding result data is available. `CommandResult` exposes `status`, `stdout`, `stderr`, `data`, and `succeeded()`.

`ContainerManagerInterface` currently covers image availability/pull/build, networks, single-container create/start/stop/inspect/status/readiness/exec/destroy, and create from one Compose service. `exec()` is a command invocation, not an interactive terminal session.

## Use runtime configuration modules

Use a module when the resource has semantics beyond a single container:

- **Compose**: `Container\Module\Compose\PodmanCompose` invokes `podman compose` and its configured provider on the client host. `ComposeInstanceDefinition` selects the file, service, and optional container name; the command adapter also supports `create --compose-file ... --service ...`.
- **Kube Play**: `Container\Module\KubePlay\PodmanKubePlay` accepts a `KubePlayDefinition` and exposes `play()` / `down()`. The manifest is read on the caller side and streamed to the selected Podman host; networks are explicit.

Do not force Compose or Kube Play into `ContainerManagerInterface` lifecycle methods if that would hide pod-level or multi-resource behavior. A future format such as Quadlet should get a module with its own truthful API.

## Add or change an integration

1. Read the relevant public interface, definition, driver/module, command adapter, and tests before changing behavior.
2. Put generic container behavior in this package. Put domain policy and application workflow in the consumer.
3. Preserve local and remote Podman behavior. In tests, inherit the caller's `CONTAINER_CONNECTION`; never force local Podman or encode a personal connection.
4. Give each independently reportable behavior its own test. Use concise test descriptions that name the feature and observable result. Skip a case only when a genuine host prerequisite is missing; dependent cases should skip with it.
5. Run focused tests for changed code, then `composer test` when the available Podman host and runtime prerequisites permit. Report unavailable prerequisites explicitly rather than silently changing the selected host.
6. Review `git diff` and `git status` before committing; do not leave unrelated or previously pending changes out of an otherwise necessary commit without first resolving their ownership.

The test bootstrap loads the project `.env` followed by `tests/.env`; values supplied by the invoking process take precedence. Keep personal machine settings in ignored `.env` files and document only anonymous examples in tracked files.

## Current limits to preserve accurately

- Podman is the implemented container driver. Docker is not yet an implemented driver.
- Compose depends on the provider configured for Podman on the target host.
- `exec()` runs a command and returns its result; it does not currently attach an interactive PTY.
- Direct-address networks and published ports are distinct network choices; do not imply that one makes the other unavailable unless the specific Podman network mode requires it.
- `create()` in the PHP API does not start the container; the shell command's default workflow creates and starts it unless `--no-start` is requested.
