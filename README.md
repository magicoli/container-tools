# Container Tools

A small PHP library for Podman container lifecycle operations, with a command adapter for shell callers.

The first milestone covers image availability, container creation and startup, running-state inspection, and readiness probes. It does not install or configure applications inside containers.

The public API is under `Magicoli\ContainerTools\Container`: `ContainerManagerInterface` defines the driver contract, `ContainerDefinition` describes a container, and `Container\Driver\Podman` implements the contract. The command in `bin/` is a thin shell-facing adapter over that API.

## PHP

```php
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

$podman = new Podman(connection: getenv('CONTAINER_CONNECTION') ?: null);
$podman->pull('docker.io/library/alpine:3.22');
$podman->create(new ContainerDefinition('example', 'docker.io/library/alpine:3.22', [], null, [
    'sh', '-c', 'while :; do sleep 3600; done',
]));
$podman->start('example');
$ready = $podman->waitUntilReady('example', ['sh', '-c', 'test -r /etc/os-release']);
```

## Bash

Composer exposes `container-tools.php` as a binary. `CONTAINER_CONNECTION` selects a configured Podman connection, and `PODMAN_BINARY` can select a non-default Podman executable.

```bash
container-tools.php image-pull docker.io/library/alpine:3.22
container-tools.php create --name example --image docker.io/library/alpine:3.22 -- sh -c 'while :; do sleep 3600; done'
container-tools.php start example
container-tools.php ready example -- sh -c 'test -r /etc/os-release'
container-tools.php running example
```

## Tests

`composer test` runs unit tests. The two real-container tests are opt-in:

```bash
PODMAN_INTEGRATION=1 composer test -- --filter='Podman integration'
```

They create uniquely named containers and remove them afterward. Set `CONTAINER_CONNECTION` to use a remote Podman connection and `PODMAN_TEST_IMAGE` to override the Alpine test image.
