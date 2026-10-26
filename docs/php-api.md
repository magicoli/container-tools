# PHP API reference

This is the PHP library reference for `magicoli/container-tools`. It documents the public types and their behavior; it is not an HTTP/OpenAPI description. PHPDoc on the public interfaces and value objects is the source-level contract. Update this page and the PHPDoc together when changing that contract.

## Requirements and namespace

- PHP 8.2 or newer
- `ext-curl`
- `guzzlehttp/guzzle` 7.9 or newer
- `tar` on the client host for image builds

Public classes use the `Magicoli\ContainerTools\Container` namespace and PSR-4 autoloading. Install the Composer package and load its autoloader:

```php
require __DIR__ . '/vendor/autoload.php';

use Magicoli\ContainerTools\Container\ContainerManagerInterface;
use Magicoli\ContainerTools\Container\Driver\Podman;

/** @var ContainerManagerInterface $containers */
$containers = new Podman();
```

The current driver is Podman. `Podman` talks to Podman's Docker-compatible REST API. Compose and Kube Play are separate modules that invoke the corresponding Podman command/provider because they manage resources with different lifecycles.

## Connection selection

`new Podman($connection)` uses the named Podman connection. If omitted, the driver uses `CONTAINER_CONNECTION`, then Podman's default configured connection/socket. `CONTAINER_HOST` or `DOCKER_HOST` can select a direct endpoint. The caller owns loading configuration files; this package does not load `.env`.

The CLI adapter accepts `-c|--connection NAME` or `--connection=NAME`, which takes precedence for that invocation. Preserve caller-provided environment variables when launching it.

Connection resolution and API negotiation happen in the constructor. It throws `RuntimeException` if the cURL extension is unavailable, the connection cannot be resolved, or the endpoint cannot negotiate a compatible API version. Once constructed, operation and transport failures are represented by `CommandResult` unless a caller-provided HTTP client itself throws outside the handled request path.

For a remote connection, container/network operations execute against the selected Podman host. Image build context and Containerfile are local to the PHP process and are uploaded to the API. Compose invokes `podman compose` and its provider on the client host; file and volume source behavior follows that provider, so verify volume source paths with it. Kube Play invokes the Podman CLI on the client host, reads its manifest locally, and streams the content to the selected Podman host.

## Result contract

Every lifecycle operation returns `CommandResult`:

| Property/method | Contract |
|---|---|
| `status: int` | `0` means success. A nonzero value is the engine/command exit status or a library-generated error code. |
| `stdout: string` | Captured operation output, when available. |
| `stderr: string` | Diagnostic output, when available. |
| `data: array<string, mixed>` | Structured operation context. Keys depend on the operation and may be partial on failure. |
| `succeeded(): bool` | Equivalent to `status === 0`. |
| `withData(array $data): self` | Immutable copy; supplied keys replace matching keys in the existing `data`. |

Check `succeeded()` before using success-only fields. Do not parse the CLI's human-readable summary or the nested raw Podman API document to retrieve values already exposed in normalized `data` fields.

### Normalized container details

Successful inspect/status and lifecycle results include `data['container']` when the container can be inspected:

```php
[
    'id' => 'full-id',
    'short_id' => 'short-id',
    'name' => 'podman-assigned-or-requested-name',
    'image' => 'image-reference',
    'hostname' => 'container-hostname',
    'status' => 'created|running|exited|...',
    'open_stdin' => true,
    'tty' => true,
    'ports' => [
        ['container' => '80/tcp', 'host_ip' => '0.0.0.0', 'host_port' => '8080'],
    ],
    'networks' => [
        'network-name' => ['ipv4' => '...', 'ipv6' => '...', 'gateway' => '...'],
    ],
    'api' => [/* complete Podman inspection document */],
]
```

Fields from Podman may be `null` when the engine omits them. `ports` contains effective bindings reported by the engine, not merely the request.

## Lifecycle interface

`ContainerManagerInterface` is the driver contract. All names accepted by lifecycle methods may be a container name or ID unless the method specifically requires a network name.

| Method | Behavior and result data |
|---|---|
| `info(): CommandResult` | Returns the selected server system information as JSON in `stdout`, including its `Name` (hostname). |
| `hostPorts(string $image = ContainerDefinition::DEFAULT_IMAGE): CommandResult` | Lists occupied TCP/UDP ports on the selected Podman host as a JSON array. Uses a temporary unprivileged host-network probe and removes it after inspection. |
| `imageAvailable(string $image): bool` | Checks local availability on the selected engine. `false` covers both missing image and failed lookup/connection; use `pull()` when the distinction is not needed. |
| `pull(string $image): CommandResult` | Pulls only when missing. `data`: `operation`, `summary`, `image`, `pulled`. |
| `build(ImageBuildDefinition $image): CommandResult` | Builds from a client-local context; Containerfile must be inside that context. `data` includes `operation`, `summary`, `image`, `from`, `no_cache`, `image_existed`, `build_steps`, `cached_steps`, `image_id` where available. Summary distinguishes unchanged/cache-tagged/rebuilt/new/no-cache results. |
| `create(ContainerDefinition $container): CommandResult` | Pulls image if missing, then creates a **stopped** container. It does not start it. Image `EXPOSE` ports are published on engine-selected host ports by default; explicit mappings replace defaults for the same container port/protocol and add other mappings. Success `data` includes normalized `container`, `requested`, `image_pulled`, `default_ports`, `published_ports_requested`, and pasta requested/active flags. A nonzero result can follow partial creation; inspect `summary`, `id`, and other result data before deciding whether cleanup is needed. |
| `createFromCompose(ComposeInstanceDefinition $instance): CommandResult` | Uses the Compose provider configured on the client host to create one selected service **without starting it**. Fails if the service cannot be resolved to exactly one container. `data` includes normalized `container`, `requested`, and generated `compose_project` when available. A failure may occur after Compose has created resources. |
| `start(string $name): CommandResult` | Starts a stopped container. `data`: `operation`, `summary`, `container` (post-operation details when inspect succeeds, otherwise `null`). |
| `stop(string $name): CommandResult` | Stops a running container. Same lifecycle result fields as `start()`. |
| `inspect(string $name): CommandResult` | Inspects a container. Success `data`: `operation`, `summary`, `container`. Failure includes `reference`; API errors may include `http_status`. |
| `status(string $name): CommandResult` | Returns current state in `stdout` and normalized details in `data['container']`. |
| `waitUntilReady(string $name, list<string> $probe, int $timeoutSeconds = 30, int $intervalMicroseconds = 250_000): CommandResult` | Repeatedly executes the argv probe without a shell. `data` adds `operation`, `summary`, `probe`, and `elapsed_seconds`. A timeout returns the last probe's nonzero status/output. |
| `exec(string $name, non-empty-list<string> $command): CommandResult` | Executes argv in a running container without a shell, stdin attachment, or interactive TTY. `status` is the command exit code; `data` includes `operation`, `summary`, container reference, and `exit_code`. |
| `copyTo(string $name, string $source, string $destination): CommandResult` | Streams a local file or directory to the exact absolute destination path through the container archive API. The destination's parent directory must already exist; the container may be stopped. `data` includes `operation`, `summary`, container reference, source, and destination. |
| `destroy(string $name): CommandResult` | Inspects, force-stops if needed, then removes the container. `data['container']` is its pre-removal normalized detail. It refuses removal if the initial inspection fails. If Podman reports a cleanup error but a follow-up inspection confirms the container is gone, the result succeeds and includes `data['warning']`. |
| `createNetwork(NetworkDefinition $network): CommandResult` | Creates a network. `data` includes `operation`, `summary`, `network`, and `requested`. |
| `inspectNetwork(string $name): CommandResult` | Inspects a network. `data` includes `operation`, `summary`, and `network`. |
| `destroyNetwork(string $name): CommandResult` | Removes a network; the engine rejects removal if still in use. `data` includes `operation`, `summary`, and network name. |

The Podman constructor negotiates API compatibility before an operation. Definition constructors throw `InvalidArgumentException` for invalid values. Some operation-specific input failures are returned as nonzero `CommandResult` instead; check both the relevant definition and method documentation.

## Input definitions

### `ContainerDefinition`

```php
new ContainerDefinition(
    name: 'web',                          // null lets Podman assign a name
    image: 'docker.io/library/caddy:2',   // default: Alpine 3.24
    labels: ['role' => 'frontend'],
    memory: '1g',
    command: [],                          // empty keeps the image's command
    hostname: null,                       // defaults to provided name
    openStdin: true,
    tty: true,
    portForwards: [new PortForward(8080, 80)],
    network: null,
    publishExposedPorts: true,
    pastaAutoPublish: false,
);
```

`labels` accepts an associative `key => value` map or a list of `KEY=VALUE` strings. `memory` accepts integer amounts with optional `k`, `m`, or `g` suffix and optional `b` (for example `512m`, `1g`).

`PortForward` fields are host port (nullable for automatic assignment), container port, protocol (`tcp` or `udp`), and optional host bind IP. `PortForward::parse()` accepts one CLI-style mapping; `parseList()` accepts comma-separated mappings and equal-length ranges, for example `8080:80,8022:22,8443:443` or `8000-8002`. An unqualified single port such as `80` requests an automatic host port; an unqualified range maps the same range on both sides. IPv6 host addresses use brackets, such as `[::1]:8080:80`.

`NetworkAttachment` selects an existing network or the `host` / `pasta` network mode and may request a fixed IPv4/IPv6 address. Pasta accepts `pasta:<options>` as well. Static IPs cannot be assigned with `host` or `pasta`. Direct `macvlan`/`ipvlan` attachments have host/network requirements; see [README networking](../README.md#container-networking).

### `ImageBuildDefinition`

Requires `file`, `tag`, and `context`. `file` must be inside `context`; both are paths on the client running PHP. Optional `from` overrides the first `FROM`, and `noCache` disables layer-cache reuse. Build and create are separate calls; `create()` never builds implicitly.

### `NetworkDefinition`

Fields are `name`, `driver` (`bridge`, `macvlan`, `ipvlan`), optional parent interface, IPAM driver (`host-local`, `dhcp`, `none`), optional IPv4/IPv6 CIDR and matching gateway, and IPv6 enablement. Constructor validates enum values and address syntax. DHCP cannot be combined with declared static subnets. A gateway requires its subnet; an IPv6 subnet requires IPv6 enabled.

### `ComposeInstanceDefinition`

Requires a client-local Compose `file` and `service`, with optional `name`. The Compose provider must be configured for Podman. Compose configuration controls runtime options; the library only selects one service and reports the resulting single container.

## Runtime modules

### Compose

`PodmanCompose::up(file, service, project, containerName)` calls `podman compose ... up --detach --no-start` on the client host. It returns provider output and exit status. `Podman::createFromCompose()` wraps this operation and resolves the created container through the Podman API. The provider executable/configuration must exist on the machine where the PHP process invokes Podman's Compose command.

### Kube Play

`PodmanKubePlay` accepts an optional connection name; otherwise it follows `CONTAINER_CONNECTION`. `play(KubePlayDefinition)` runs Podman kube play; `down()` removes resources described by that manifest. The manifest is read on the PHP host and streamed to Podman via stdin, so the file itself need not exist on a remote Podman host. The definition includes client-local `file`, explicit Podman `networks`, and optional `replace` for play. Results include `operation`, `summary`, `manifest`, `networks`, `replace`, and `connection` when the command starts; file/launch failures return nonzero status and available context.

## Typical lifecycle

```php
$created = $containers->create(new ContainerDefinition(
    image: 'docker.io/library/caddy:2',
    portForwards: [new PortForward(8080, 80)],
));
if (!$created->succeeded()) {
    throw new \RuntimeException($created->stderr);
}

$name = $created->data['container']['name'];
$started = $containers->start($name);
if (!$started->succeeded()) {
    throw new \RuntimeException($started->stderr);
}

$ready = $containers->waitUntilReady($name, ['wget', '-q', '-O', '/dev/null', 'http://127.0.0.1/']);
if (!$ready->succeeded()) {
    throw new \RuntimeException($ready->stderr);
}
```

When an application requires cleanup after partial failure, track the created name/ID as soon as `create()` succeeds and call `destroy()` in the application's cleanup path.
