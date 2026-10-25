# Container Tools

A small PHP library for Podman container lifecycle operations, with a command adapter for shell callers.

The first milestone covers image builds from a local Containerfile and build context, image availability, container creation and startup, state inspection, readiness probes, command execution, stopping, and destruction. Container operations use Podman's official Docker-compatible REST API; Guzzle handles HTTP and cURL handles the Unix socket transport. Podman's configured SSH connection is used only to establish an SSH tunnel to its API socket. Runtime configuration modules live separately under `Container\Module`: Compose delegates to Podman's configured Compose provider, and Kube Play delegates Kubernetes manifests to Podman. These modules keep their own lifecycle APIs instead of pretending that different deployment formats have identical semantics.

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
- `network create|inspect|destroy` manages Podman networks for containers that need a direct network address.
- `create --compose-file FILE --service NAME [--name INSTANCE]` creates one container from a service in a Compose file. Compose holds the service's image, ports, volumes, configuration files, environment and command; the normal `start`, `stop`, `exec`, `status` and `destroy` operations remain available afterward.

### Compose instance configuration

Use the Compose Specification for runtime settings instead of translating them into a second container-tools configuration language. Podman's `compose` command delegates the file to its configured Compose provider, so a provider must be installed alongside Podman. This library calls `podman compose`; the selected provider follows Podman's `PODMAN_COMPOSE_PROVIDER` or `containers.conf` setting.

```yaml
services:
  caddy:
    image: docker.io/library/caddy:2-alpine
    container_name: ${CONTAINER_TOOLS_CONTAINER_NAME:?pass --name to container-tools}
    ports:
      - "8080:80"
      - "8443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
```

Create the configured instance; `--name` is available to Compose interpolation as `CONTAINER_TOOLS_CONTAINER_NAME`:

```bash
./bin/container-tools create --name web --compose-file ./compose.yaml --service caddy
./bin/container-tools status web
./bin/container-tools exec web -- caddy version
./bin/container-tools destroy web
```

The PHP API accepts the same standard file and service selection. `createFromCompose()` creates the service without starting it, just like `create()`; call `start()` when it is ready:

```php
use Magicoli\ContainerTools\Container\ComposeInstanceDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

$podman = new Podman();
$created = $podman->createFromCompose(new ComposeInstanceDefinition(
    file: __DIR__ . '/compose.yaml',
    service: 'caddy',
    name: 'web',
));
$podman->start($created->data['container']['name']);
```

Compose provider-specific support is determined by the provider installed on the Podman host. The integration tests verify the actual container configuration through Podman's REST API and pass with both Docker Compose and `podman-compose` as Podman's configured provider. The implementation is in `Container\Module\Compose`; `Podman` uses it when a caller asks to create a single container from a Compose service.

### Kube Play

Use the Kube Play module to deploy or remove Podman pods from Kubernetes YAML. It passes the manifest on standard input, so the same API works with a remote `CONTAINER_CONNECTION` without requiring the manifest file to exist on the remote host. Networks are explicit: Podman hosts may not have the default Kubernetes network, so container-tools does not silently choose one.

```php
use Magicoli\ContainerTools\Container\Module\KubePlay\KubePlayDefinition;
use Magicoli\ContainerTools\Container\Module\KubePlay\PodmanKubePlay;

$kube = new PodmanKubePlay(); // Honors CONTAINER_CONNECTION.
$manifest = new KubePlayDefinition(
    file: __DIR__ . '/pod.yaml',
    networks: ['podman'],
    replace: true,
);
$created = $kube->play($manifest);
if (!$created->succeeded()) {
    throw new RuntimeException($created->stderr);
}

// Later, remove the resources described by the same manifest:
$removed = $kube->down($manifest);
```

The corresponding Podman operations are `podman kube play` and `podman kube play --down`. The module returns their output and exit status in `CommandResult`, together with the selected manifest, network list, connection and operation summary. Kube Play creates and manages pods, which have a different lifecycle from the single containers managed by `ContainerManagerInterface`.

### Module layout

Each deployment format gets a namespace under `Container\Module` and exposes the operations appropriate to that format. Compose and Kube Play are implemented as separate modules; future formats such as Quadlet can add their own module without changing the Podman REST driver or forcing a common lifecycle onto unlike deployment resources. The module layer is deliberately distinct from container-engine drivers such as Podman and a future Docker driver.

## Container networking

Choose host-port publishing, automatic `pasta` forwarding, or a direct address on a network. The library can combine explicit host-port mappings with a `macvlan` or `ipvlan` address by attaching the container to both its direct network and a bridge network for the published ports.

`IPAM` means IP address management: it selects how addresses are assigned to a Podman network. `host-local` assigns addresses from the configured subnet, `dhcp` asks a DHCP server, and `none` leaves address assignment to another mechanism.

### Host port forwarding

The library reads the image's `EXPOSE` metadata and publishes those ports on automatically selected host ports by default. Podman chooses free ports, so multiple instances can run together without fighting over host ports. An explicit `--publish` mapping for an exposed container port replaces that default; a mapping for a different port is added alongside it. Use `--no-publish-defaults` to turn off the automatic mappings.

Publish a container service on a specific host port. The host and container ports can differ, or Podman can choose an unused host port:

```bash
./bin/container-tools create --name web --image docker.io/library/nginx:alpine --publish 8080:80
./bin/container-tools wait-ready web -- wget -q -O /dev/null http://127.0.0.1/
curl http://127.0.0.1:8080/
./bin/container-tools status web
./bin/container-tools destroy web
```

Repeat `--publish` or separate entries with commas. Add `/udp` for UDP ports. `--publish 53/udp` requests an automatically chosen host port. Podman chooses an available host port from its ephemeral port range; it does not try port 53 first or start searching at 8000. The result reports the effective host-to-container mappings.

Ranges are expanded into individual mappings. A range without a colon uses the same port numbers on the host and in the container; with host and container ranges, their lengths must match:

```bash
./bin/container-tools create --name services --image docker.io/library/alpine:3.24 \
  --publish=8080:80,8022:22,8443:443
./bin/container-tools create --name opensim-ports --image docker.io/library/alpine:3.24 \
  --publish=8002-8100,9000-9100
./bin/container-tools create --name random-ports --image docker.io/library/alpine:3.24 \
  --publish=:8002-8100
```

Use `--pasta-auto-publish` for rootless Podman to forward listening ports dynamically in either direction. This is a create-time network setting; `EXPOSE` remains image metadata and is declared in the Containerfile. A later listener opened by a process in the container is forwarded on the same port by pasta. Explicit `--publish` mappings still work with pasta:

```bash
./bin/container-tools create --name dynamic-web --image docker.io/library/alpine:3.24 \
  --pasta-auto-publish -- sh -c 'sleep 3600'
```

Automatic pasta forwarding requires rootless Podman. If a different named network is selected, the container keeps its normal image/explicit port mappings and network address; the result reports that pasta auto-forwarding was not active. Direct `macvlan`/`ipvlan` networks and host-port mappings can be combined: the library uses a bridge attachment for published ports and keeps the direct network address as well.

### Direct address from DHCP

On a Linux Podman host, create a macvlan network on the physical LAN interface and let the LAN's DHCP server assign the container address:

```bash
sudo systemctl enable --now netavark-dhcp-proxy.socket
sudo ./bin/container-tools network create --name opensim-lan --driver macvlan --parent enp3s0 --ipam dhcp
sudo ./bin/container-tools create --name opensim-1 --image localhost/opensim:latest --network opensim-lan
sudo ./bin/container-tools status opensim-1
```

Use the address printed for the `opensim-lan` network to reach the container directly; no host-port mapping is involved. Omit `--parent` to let Podman use the default route interface. The DHCP IPAM mode needs Podman's `netavark-dhcp-proxy.socket`. It obtains an IPv4 lease; Podman's current DHCP IPAM does not promise a DHCPv6 lease, so use a static IPv6 address or router-advertised addressing when IPv6 is needed.

### Direct fixed IPv4 and/or IPv6

Create a macvlan network with the LAN's actual subnets and gateways, then choose an unused address for each container:

```bash
sudo ./bin/container-tools network create \
  --name opensim-fixed --driver macvlan --parent enp3s0 --ipam host-local \
  --ipv4-subnet 192.168.1.0/24 --ipv4-gateway 192.168.1.1 \
  --ipv6-subnet 2001:db8:1234::/64 --ipv6-gateway 2001:db8:1234::1 --ipv6
sudo ./bin/container-tools create --name opensim-2 --image localhost/opensim:latest \
  --network opensim-fixed --ip4 192.168.1.52 --ip6 2001:db8:1234::52
```

Omit either subnet/gateway pair and its corresponding `--ip4` or `--ip6` option to use only IPv4 or IPv6. Replace the documentation IPv6 range above with a prefix routed to the host; `2001:db8::/32` is reserved for examples and is not publicly routable.

These direct-LAN modes depend on the Linux host's network, permissions, interface and router configuration. Podman's macvlan/ipvlan drivers cannot access host interfaces from rootless mode and do not support published ports. On macOS and Windows, Podman runs inside a Linux virtual machine, so a macvlan parent refers to an interface in that VM, not directly to the physical host LAN; direct LAN addressing must be verified on the target Linux Podman server. If the network should stay private to Podman, use the ordinary bridge network and publish only the ports you need.

Remove a direct network after removing all containers attached to it:

```bash
sudo ./bin/container-tools destroy opensim-2
sudo ./bin/container-tools network destroy opensim-fixed
```

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

`bin/container-tools` is a Bash wrapper around the PSR-4 command in `src/Console`. `-c|--connection NAME` and `--connection=NAME` select a configured Podman connection (the same option Podman uses); otherwise the standard Podman `CONTAINER_CONNECTION` setting is inherited. `CONTAINER_HOST` can point directly to a Unix socket or HTTP endpoint.

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
# Open an interactive shell in the running container:
podman exec -it debian-caddy /bin/bash
```

The Alpine recipe is at `examples/caddy/alpine/Containerfile`. Both examples share the Caddyfile and entrypoint in `examples/caddy/`. Open an interactive shell in an Alpine container with `podman exec -it alpine-caddy /bin/sh`. `EXPOSE` records image port metadata; this library uses that metadata for its default automatic host mappings when creating a container.

## Tests

`composer test` runs unit tests and real Podman integration tests. Podman, PHP with cURL, and `tar` are required. The Compose integration cases run when Podman has a Compose provider configured; if none is installed, those cases are reported as skipped with the missing prerequisite. Install Docker Compose or `podman-compose` to run them. The integration suite builds the Debian 12 and Alpine Caddy/OpenSSH examples, checks a Compose-configured instance, exercises lifecycle and readiness probes, then removes the containers. Use Podman’s standard `CONTAINER_CONNECTION` variable or pass `--connection NAME` to select a configured Podman connection.

# Roadmap

- [ ] OpenAPI specifications
- [ ] AI skills
- [ ] [Quadlet support](https://www.stephane-robert.info/docs/conteneurs/moteurs-conteneurs/podman/quadlet/)
- [ ] Pools: pools of connections to choose from, with best guess (load, cpu, memory...)
- [ ] Quota: limit usage per pool, categories, host, ...

## Contributing and integration

See [DEVELOPERS.md](DEVELOPERS.md) for the PHP API, shell command integration, connection configuration, and guidance for consuming applications. A reusable Agent Skills guide is available at [`.agents/skills/container-tools-integration/SKILL.md`](.agents/skills/container-tools-integration/SKILL.md).
