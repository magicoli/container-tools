# Container Tools

A small PHP library for Podman container lifecycle operations, with a command adapter for shell callers.

The first milestone covers image availability, container creation and startup, running-state inspection, and readiness probes. It does not install or configure applications inside containers.

The public API is under `Magicoli\ContainerTools\Container`: `ContainerManagerInterface` defines the driver contract, `ContainerDefinition` describes a container, and `Container\Driver\Podman` implements the contract. The command in `bin/` is a thin shell-facing adapter over that API.

## PHP

```php
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

$podman = new Podman(remote: getenv('CONTAINER_REMOTE') ?: null);
$podman->pull('docker.io/library/alpine:3.22');
$podman->create(new ContainerDefinition('example', 'docker.io/library/alpine:3.22', [], null, [
    'sh', '-c', 'while :; do sleep 3600; done',
]));
$podman->start('example');
$ready = $podman->waitUntilReady('example', ['sh', '-c', 'test -r /etc/os-release']);
```

## Bash

`bin/container-tools` is a Bash wrapper around the PSR-4 command in `src/Console`. `--remote NAME` or `CONTAINER_REMOTE=NAME` selects a configured Podman remote (the driver passes it to Podman as `--connection`), and `PODMAN_BINARY` can select a non-default Podman executable.

```bash
./bin/container-tools image-pull docker.io/library/alpine:3.22
./bin/container-tools create --name example --image docker.io/library/alpine:3.22 --start -- sh -c 'while :; do sleep 3600; done'
./bin/container-tools ready example -- sh -c 'test -r /etc/os-release'
./bin/container-tools running example
```

## Tests

`composer test` runs unit tests and real Podman integration tests. Podman and PHP are required; the integration tests create uniquely named containers and remove them afterward. Set `CONTAINER_REMOTE` or pass `--remote NAME` to use a configured Podman remote and `PODMAN_TEST_IMAGE` to override the Alpine test image.
