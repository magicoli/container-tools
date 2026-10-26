<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Console;

use InvalidArgumentException;
use Magicoli\ContainerTools\Container\CommandResult;
use Magicoli\ContainerTools\Container\ComposeInstanceDefinition;
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\ContainerManagerInterface;
use Magicoli\ContainerTools\Container\ImageBuildDefinition;
use Magicoli\ContainerTools\Container\NetworkAttachment;
use Magicoli\ContainerTools\Container\NetworkDefinition;
use Magicoli\ContainerTools\Container\PortForward;

final class ContainerToolsCommand
{
    public function __construct(
        private readonly ContainerManagerInterface $manager,
    ) {}

    public function run(array $arguments): int
    {
        if (($arguments[0] ?? null) === "help") {
            $topic = $arguments[1] ?? null;
            if (
                $topic !== null &&
                !in_array(
                    $topic,
                    [
                        "info",
                        "image-pull",
                        "build",
                        "create",
                        "network",
                        "start",
                        "stop",
                        "status",
                        "wait-ready",
                        "exec",
                        "cp",
                        "destroy",
                    ],
                    true,
                )
            ) {
                fwrite(
                    STDERR,
                    "container-tools: unknown help topic '$topic'\n",
                );
                return 2;
            }
            fwrite(STDOUT, self::usage($topic));
            return 0;
        }

        [$arguments, $verbose, $quiet, $dryRun, $help] = $this->globalOptions(
            $arguments,
        );

        if ($help || in_array($arguments[0] ?? "", ["--help", "-h"], true)) {
            $topic =
                isset($arguments[0]) && !str_starts_with($arguments[0], "-")
                    ? $arguments[0]
                    : null;
            fwrite(STDOUT, self::usage($topic));
            return 0;
        }

        if ($arguments === []) {
            fwrite(STDERR, self::usage());
            return 2;
        }

        if ($dryRun) {
            if (!$quiet) {
                $command = implode(
                    " ",
                    array_map("escapeshellarg", $arguments),
                );
                fwrite(
                    STDOUT,
                    "Dry run: no action performed.\nWould run: $command\n",
                );
            }
            return 0;
        }

        $action = array_shift($arguments) ?? "";
        try {
            $result = match ($action) {
                "info" => $arguments === [] ? $this->manager->info() : null,
                "image-pull" => count($arguments) === 1
                    ? $this->manager->pull($arguments[0])
                    : null,
                "build" => $this->build($arguments),
                "create" => $this->create($arguments),
                "network" => $this->network($arguments),
                "start" => count($arguments) === 1
                    ? $this->manager->start($arguments[0])
                    : null,
                "stop" => count($arguments) === 1
                    ? $this->manager->stop($arguments[0])
                    : null,
                "status" => count($arguments) === 1
                    ? $this->manager->status($arguments[0])
                    : null,
                "wait-ready" => $this->ready($arguments),
                "exec" => $this->execute($arguments),
                "cp" => $this->copyTo($arguments),
                "destroy" => count($arguments) === 1
                    ? $this->manager->destroy($arguments[0])
                    : null,
                default => null,
            };
        } catch (InvalidArgumentException $exception) {
            fwrite(
                STDERR,
                "container-tools: " . $exception->getMessage() . "\n",
            );
            return 2;
        }

        if (!($result instanceof CommandResult)) {
            fwrite(STDERR, self::usage());
            return 2;
        }
        if (!$quiet) {
            fwrite(STDOUT, $this->render($result, $verbose));
        }
        fwrite(STDERR, $result->stderr);

        return $result->status;
    }

    private static function usage(?string $topic = null): string
    {
        if ($topic !== null) {
            $help = match ($topic) {
                "info" => "usage: container-tools info\n\nShow the selected server system information as JSON.\n",
                "image-pull" => <<<'HELP'
                usage: container-tools image-pull IMAGE

                Download an image to the selected Podman engine. Container creation also pulls
                the image automatically when it is not already available.
                HELP,
                "build" => <<<'HELP'
                usage: container-tools build --file FILE --tag IMAGE [options] [CONTEXT]

                Build and tag an image from a Containerfile. CONTEXT defaults to the directory
                containing FILE. Build and create are separate operations; create never builds.

                Options:
                      --from IMAGE     Override the first FROM image
                      --no-cache       Do not reuse cached build layers

                Example:
                  container-tools build --file examples/caddy/debian12/Containerfile \
                    --tag localhost/caddy:debian12 examples/caddy
                HELP,
                "create" => <<<'HELP'
                usage: container-tools create [options] [-- COMMAND...]

                Create and start a container. If NAME is omitted, Podman assigns a readable
                name. If IMAGE is missing locally, it is pulled first. Without COMMAND, the
                image's configured command and entrypoint are used.

                Options:
                      --name NAME       Container name (Podman chooses one if omitted)
                      --image IMAGE     Image to use (default: Alpine 3.24)
                      --compose-file FILE Compose configuration file for this instance
                      --service NAME    Service in the Compose file (required with --compose-file)
                      --hostname NAME   Hostname (defaults to the container name)
                      --memory SIZE     Memory limit
                      --label KEY=VALUE Add a label (repeatable)
                      --publish MAPPING Publish one or more host ports (repeatable)
                      --no-publish-defaults Do not publish ports declared by the image
                      --network MODE    Use pasta or attach to a named network
                      --pasta-auto-publish Automatically forward listening ports in both directions (pasta mode)
                      --ip4 ADDRESS     Request a fixed IPv4 address on that network
                      --ip6 ADDRESS     Request a fixed IPv6 address on that network
                      --no-start        Create the container without starting it
                      -- COMMAND...     Override the image command

                Port mappings may be comma-separated in one --publish value or supplied by
                repeating --publish. Use HOST_PORT:CONTAINER_PORT to choose a host port,
                CONTAINER_PORT to let Podman choose a host port, or :CONTAINER_PORT to make
                that automatic assignment explicit. Prefix HOST_IP: to bind only that host
                address; bracket IPv6 addresses, for example [::1]:8080:80. An unqualified
                range such as 8002-8100 maps the same range on both sides;
                HOST_RANGE:CONTAINER_RANGE maps equal-sized ranges. Add /udp for UDP. Podman
                chooses an available ephemeral host port; it does not first try the container
                port or search from port 8000.

                Examples:
                  container-tools create --image nginx:alpine --publish 8080:80
                  container-tools create --publish 8080:80,8022:22,8443:443
                  container-tools create --publish 8002-8100,9000-9100
                  container-tools create --publish :8002-8100
                HELP,
                "network" => <<<'HELP'
                usage: container-tools network <create|inspect|destroy> [options]

                Create or manage a Podman network. IPAM means IP address management: it
                selects how addresses are assigned. host-local assigns from the configured
                subnet, dhcp requests a DHCP lease, and none leaves address assignment to
                another mechanism.

                Create options:
                      --name NAME          Network name
                      --driver DRIVER      bridge (default), macvlan, or ipvlan
                      --parent IFACE       Parent interface for macvlan/ipvlan; defaults to
                                           Podman's default route interface when omitted
                      --ipam DRIVER        host-local (default), dhcp, or none
                      --ipv4-subnet CIDR   IPv4 subnet for fixed addresses
                      --ipv4-gateway IP    IPv4 gateway
                      --ipv6-subnet CIDR   IPv6 subnet for fixed addresses
                      --ipv6-gateway IP    IPv6 gateway
                      --ipv6               Enable IPv6

                Examples:
                  container-tools network create --name lan --driver macvlan --ipam dhcp
                  container-tools network create --name fixed --driver macvlan \
                    --ipv4-subnet 192.168.1.0/24 --ipv4-gateway 192.168.1.1
                  container-tools network inspect lan
                  container-tools network destroy lan

                macvlan and ipvlan require host-interface access and do not support published
                host ports. DHCP networks require the Podman host's DHCP integration to be
                configured.
                HELP,
                "start"
                    => "usage: container-tools start NAME\n\nStart a stopped container and report its state.",
                "stop"
                    => "usage: container-tools stop NAME\n\nStop a running container.",
                "status"
                    => "usage: container-tools status NAME\n\nShow lifecycle state, published ports, and network addresses.",
                "wait-ready"
                    => "usage: container-tools wait-ready NAME -- PROBE...\n\nRepeat PROBE in the container until it succeeds or the readiness timeout expires.",
                "exec"
                    => "usage: container-tools exec NAME -- COMMAND...\n\nRun COMMAND inside a running container.",
                "cp" => <<<'HELP'
                usage: container-tools cp SOURCE CONTAINER:DESTINATION

                Copy a local file or directory to an exact path in a container. The destination's
                parent directory must already exist. The container may be stopped or running.

                Example:
                  container-tools cp ./setup.sh opensim:/tmp/setup.sh
                HELP,
                "destroy"
                    => "usage: container-tools destroy NAME\n\nStop and remove a container.",
                default => null,
            };
            return $help === null ? self::usage() : $help . "\n";
        }

        return <<<'HELP'
        usage: container-tools [-hvqd] <command> [options]

        Type 'container-tools <command> --help' or 'container-tools help <command>'
        for details on a command.

        Server
            info                    Show server system information as JSON

        Images
            image-pull IMAGE
            build --file FILE --tag IMAGE [--from IMAGE] [--no-cache] [CONTEXT]

        Containers
            create [options] [-- COMMAND...]
            start NAME
            stop NAME
            status NAME
            wait-ready NAME -- PROBE...
            exec NAME -- COMMAND...
            cp SOURCE CONTAINER:DESTINATION
            destroy NAME

        Networks
            network create --name NAME [options]
            network inspect NAME
            network destroy NAME

        Container networking
            --publish MAPPING    Publish host ports; repeatable or comma-separated
            --network NAME       Attach to a named network
            --ip4 ADDRESS        Request a fixed IPv4 address
            --ip6 ADDRESS        Request a fixed IPv6 address

        Global options
            -h|--help                   Display this help
            -v|--verbose                Show full Podman API details
            -q|--quiet                  Suppress normal output
            -d|--dry-run                Show the action without running it
            -c|--connection NAME        Use a configured Podman connection (remote)
        HELP
        . "\n";
    }

    /** @return array{array, bool, bool, bool, bool} */
    private function globalOptions(array $arguments): array
    {
        $verbose = $quiet = $dryRun = $help = false;
        $filtered = [];
        $expectsValue = false;
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if ($argument === "--") {
                array_push($filtered, ...array_slice($arguments, $index));
                break;
            }
            if ($expectsValue) {
                $filtered[] = $argument;
                $expectsValue = false;
                continue;
            }
            if (in_array($argument, ["-v", "--verbose"], true)) {
                $verbose = true;
                continue;
            }
            if (in_array($argument, ["-q", "--quiet"], true)) {
                $quiet = true;
                continue;
            }
            if (in_array($argument, ["-d", "--dry-run"], true)) {
                $dryRun = true;
                continue;
            }
            if (in_array($argument, ["-h", "--help"], true)) {
                $help = true;
                continue;
            }
            if (
                in_array(
                    $argument,
                    [
                        "--name",
                        "--image",
                        "--hostname",
                        "--compose-file",
                        "--service",
                        "--memory",
                        "--label",
                        "--file",
                        "--tag",
                        "--from",
                        "--publish",
                        "--network",
                        "--ip4",
                        "--ip6",
                        "--driver",
                        "--parent",
                        "--ipam",
                        "--ipv4-subnet",
                        "--ipv4-gateway",
                        "--ipv6-subnet",
                        "--ipv6-gateway",
                    ],
                    true,
                )
            ) {
                $expectsValue = true;
            }
            $filtered[] = $argument;
        }

        return [$filtered, $verbose, $quiet, $dryRun, $help];
    }

    private function build(array $arguments): ?CommandResult
    {
        $file = $tag = $from = null;
        $context = null;
        $noCache = false;
        while ($arguments !== []) {
            $option = array_shift($arguments);
            if ($option === "--no-cache") {
                $noCache = true;
                continue;
            }
            if (str_starts_with($option, "--")) {
                $parts = explode("=", $option, 2);
                $name = $parts[0];
                $value =
                    count($parts) === 2 ? $parts[1] : array_shift($arguments);
                if ($value === null || $value === "") {
                    return null;
                }
                match ($name) {
                    "--file" => ($file = $value),
                    "--tag" => ($tag = $value),
                    "--from" => ($from = $value),
                    default => null,
                };
                if (!in_array($name, ["--file", "--tag", "--from"], true)) {
                    return null;
                }
                continue;
            }
            if ($context !== null) {
                return null;
            }
            $context = $option;
        }
        if ($file === null || $tag === null) {
            return null;
        }
        $context ??= dirname($file);
        return $this->manager->build(
            new ImageBuildDefinition($file, $tag, $context, $from, $noCache),
        );
    }

    private function create(array $arguments): ?CommandResult
    {
        $name = $memory = $hostname = $composeFile = $composeService = null;
        $imageSpecified = false;
        $image =
            getenv("CONTAINER_IMAGE") ?: ContainerDefinition::DEFAULT_IMAGE;
        $labels = [];
        $portForwards = [];
        $networkName = $ipv4 = $ipv6 = null;
        $start = true;
        $publishExposedPorts = true;
        $pastaAutoPublish = false;
        $command = [];
        while ($arguments !== []) {
            $option = array_shift($arguments);
            if ($option === "--") {
                $command = $arguments;
                break;
            }
            if ($option === "--no-start") {
                $start = false;
                continue;
            }
            if ($option === "--no-publish-defaults") {
                $publishExposedPorts = false;
                continue;
            }
            if ($option === "--pasta-auto-publish") {
                $pastaAutoPublish = true;
                continue;
            }
            $parts = explode("=", $option, 2);
            $option = $parts[0];
            $value = count($parts) === 2 ? $parts[1] : array_shift($arguments);
            if ($value === null) {
                return null;
            }
            if ($option === "--image") {
                $imageSpecified = true;
            }
            match ($option) {
                "--name" => ($name = $value),
                "--image" => ($image = $value),
                "--hostname" => ($hostname = $value),
                "--compose-file" => ($composeFile = $value),
                "--service" => ($composeService = $value),
                "--memory" => ($memory = $value),
                "--label" => ($labels[] = $value),
                "--publish" => array_push(
                    $portForwards,
                    ...PortForward::parseList($value),
                ),
                "--network" => ($networkName = $value),
                "--ip4" => ($ipv4 = $value),
                "--ip6" => ($ipv6 = $value),
                default => null,
            };
            if (
                !in_array(
                    $option,
                    [
                        "--name",
                        "--image",
                        "--hostname",
                        "--compose-file",
                        "--service",
                        "--memory",
                        "--label",
                        "--publish",
                        "--network",
                        "--ip4",
                        "--ip6",
                    ],
                    true,
                )
            ) {
                return null;
            }
        }
        if (($ipv4 !== null || $ipv6 !== null) && $networkName === null) {
            return null;
        }
        if ($pastaAutoPublish && $networkName === null) {
            $networkName = "pasta";
        }
        $network =
            $networkName === null
                ? null
                : new NetworkAttachment($networkName, $ipv4, $ipv6);
        if ($composeFile !== null) {
            if (
                $composeService === null ||
                $imageSpecified ||
                $hostname !== null ||
                $memory !== null ||
                $labels !== [] ||
                $portForwards !== [] ||
                $network !== null ||
                !$publishExposedPorts ||
                $pastaAutoPublish ||
                $command !== []
            ) {
                return null;
            }
            $created = $this->manager->createFromCompose(
                new ComposeInstanceDefinition($composeFile, $composeService, $name),
            );
        } else {
            if ($composeService !== null) {
                return null;
            }
            $created = $this->manager->create(
                new ContainerDefinition(
                $name,
                $image,
                $labels,
                $memory,
                $command,
                $hostname,
                portForwards: $portForwards,
                network: $network,
                publishExposedPorts: $publishExposedPorts,
                pastaAutoPublish: $pastaAutoPublish,
                ),
            );
        }
        if (!$created->succeeded() || !$start) {
            return $created;
        }
        $container = $created->data["container"] ?? [];
        $target =
            $name ??
            ($container["name"] ??
                ($container["id"] ?? trim($created->stdout)));
        if ($target === "") {
            return new CommandResult(
                1,
                $created->stdout,
                $created->stderr .
                    "Podman did not return a container name or ID\n",
                $created->data,
            );
        }
        $started = $this->manager->start($target);
        $startedContainer = $started->data["container"] ?? $container;
        $summary = !$started->succeeded()
            ? "Container created but failed to start"
            : (($startedContainer["status"] ?? null) === "running"
                ? "Container created and started"
                : "Container created; image command exited");

        return new CommandResult(
            $started->status,
            $created->stdout . $started->stdout,
            $created->stderr . $started->stderr,
            [
                ...$created->data,
                "summary" => $summary,
                "container" => $startedContainer,
            ],
        );
    }

    private function network(array $arguments): ?CommandResult
    {
        $action = array_shift($arguments);
        if ($action === "inspect" || $action === "destroy") {
            if (count($arguments) !== 1) {
                return null;
            }
            return $action === "inspect"
                ? $this->manager->inspectNetwork($arguments[0])
                : $this->manager->destroyNetwork($arguments[0]);
        }
        if ($action !== "create") {
            return null;
        }

        $values = [
            "--name" => null,
            "--driver" => "bridge",
            "--parent" => null,
            "--ipam" => "host-local",
            "--ipv4-subnet" => null,
            "--ipv4-gateway" => null,
            "--ipv6-subnet" => null,
            "--ipv6-gateway" => null,
        ];
        $ipv6 = false;
        while ($arguments !== []) {
            $option = array_shift($arguments);
            if ($option === "--ipv6") {
                $ipv6 = true;
                continue;
            }
            [$key, $value] = array_pad(explode("=", $option, 2), 2, null);
            if (!array_key_exists($key, $values)) {
                return null;
            }
            $value ??= array_shift($arguments);
            if ($value === null || $value === "") {
                return null;
            }
            $values[$key] = $value;
        }
        if ($values["--name"] === null) {
            return null;
        }
        $network = new NetworkDefinition(
            name: $values["--name"],
            driver: $values["--driver"],
            parent: $values["--parent"],
            ipamDriver: $values["--ipam"],
            ipv4Subnet: $values["--ipv4-subnet"],
            ipv4Gateway: $values["--ipv4-gateway"],
            ipv6Subnet: $values["--ipv6-subnet"],
            ipv6Gateway: $values["--ipv6-gateway"],
            ipv6Enabled: $ipv6 || $values["--ipv6-subnet"] !== null,
        );
        return $this->manager->createNetwork($network);
    }

    private function ready(array $arguments): ?CommandResult
    {
        $name = array_shift($arguments);
        if (
            $name === null ||
            array_shift($arguments) !== "--" ||
            $arguments === []
        ) {
            return null;
        }

        return $this->manager->waitUntilReady($name, $arguments);
    }

    private function execute(array $arguments): ?CommandResult
    {
        $name = array_shift($arguments);
        if ($name === null) {
            return null;
        }
        if (array_shift($arguments) !== "--" || $arguments === []) {
            return null;
        }
        return $this->manager->exec($name, $arguments);
    }

    private function copyTo(array $arguments): ?CommandResult
    {
        if (count($arguments) !== 2 || !str_contains($arguments[1], ":")) {
            return null;
        }
        [$name, $destination] = explode(":", $arguments[1], 2);
        if ($name === "" || $destination === "") {
            return null;
        }
        return $this->manager->copyTo($name, $arguments[0], $destination);
    }

    private function render(
        CommandResult $result,
        bool $verbose = false,
    ): string {
        if ($result->data === []) {
            return $result->stdout;
        }

        $lines = [];
        if (isset($result->data["summary"])) {
            $lines[] = $result->data["summary"];
        }
        foreach ($result->data["container"] ?? [] as $key => $value) {
            if ($value === null || $value === "") {
                continue;
            }
            if ($key === "api") {
                if ($verbose) {
                    $lines[] =
                        "API inspection:\n" .
                        json_encode(
                            $value,
                            JSON_PRETTY_PRINT |
                                JSON_UNESCAPED_SLASHES |
                                JSON_UNESCAPED_UNICODE,
                        );
                }
                continue;
            }
            if ($key === "ports") {
                foreach ($value as $port) {
                    $host =
                        ($port["host_ip"] ?? "") !== ""
                            ? $port["host_ip"] . ":"
                            : "";
                    $lines[] =
                        "Published port: " .
                        ($host ?: "host") .
                        ($port["host_port"] ?? "?") .
                        " -> " .
                        ($port["container"] ?? "?");
                }
                continue;
            }
            if ($key === "networks") {
                foreach ($value as $networkName => $addresses) {
                    $ips = array_filter([
                        $addresses["ipv4"] ?? null,
                        $addresses["ipv6"] ?? null,
                    ]);
                    $lines[] =
                        "Network: " .
                        $networkName .
                        (count($ips) > 0
                            ? " (" . implode(", ", $ips) . ")"
                            : "");
                }
                continue;
            }
            $label = match ($key) {
                "id" => "ID",
                "short_id" => "Short ID",
                "name" => "Name",
                "image" => "Image",
                "hostname" => "Hostname",
                "status" => "Status",
                "open_stdin" => "Open stdin",
                "tty" => "TTY",
                "reference" => "Reference",
                default => ucfirst(str_replace("_", " ", $key)),
            };
            $display = is_bool($value)
                ? ($value
                    ? "yes"
                    : "no")
                : (is_scalar($value)
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_SLASHES));
            $lines[] = "$label: $display";
        }
        if (
            isset($result->data["network"]) &&
            is_array($result->data["network"])
        ) {
            $network = $result->data["network"];
            foreach (
                [
                    "Name" => "Name",
                    "name" => "Name",
                    "Driver" => "Driver",
                    "EnableIPv6" => "IPv6 enabled",
                    "IPv6Enabled" => "IPv6 enabled",
                    "Id" => "ID",
                    "id" => "ID",
                ]
                as $key => $label
            ) {
                if (isset($network[$key])) {
                    $lines[] =
                        $label .
                        ": " .
                        (is_bool($network[$key])
                            ? ($network[$key]
                                ? "yes"
                                : "no")
                            : $network[$key]);
                }
            }
            $parent =
                $network["Options"]["parent"] ??
                ($network["NetworkInterface"] ?? null);
            if ($parent !== null && $parent !== "") {
                $lines[] = "Parent interface: " . $parent;
            }
            $ipam = $network["IPAM"] ?? null;
            if (is_array($ipam)) {
                if (isset($ipam["Driver"])) {
                    $lines[] = "IPAM: " . $ipam["Driver"];
                }
                foreach ($ipam["Config"] ?? [] as $config) {
                    if (isset($config["Subnet"])) {
                        $lines[] = "Subnet: " . $config["Subnet"];
                    }
                    if (isset($config["Gateway"])) {
                        $lines[] = "Gateway: " . $config["Gateway"];
                    }
                }
            }
        }
        foreach (
            [
                "image" => "Image",
                "pulled" => "Pulled",
                "image_pulled" => "Image pulled",
                "elapsed_seconds" => "Elapsed seconds",
                "exit_code" => "Exit code",
            ]
            as $key => $label
        ) {
            if (
                array_key_exists($key, $result->data) &&
                $result->data[$key] !== null
            ) {
                $lines[] =
                    "$label: " .
                    (is_bool($result->data[$key])
                        ? ($result->data[$key]
                            ? "yes"
                            : "no")
                        : (string) $result->data[$key]);
            }
        }
        if (
            isset($result->data["default_ports"]) &&
            $result->data["default_ports"] !== []
        ) {
            $lines[] =
                "Image ports: " . implode(", ", $result->data["default_ports"]);
        }
        if (
            isset($result->data["published_ports_requested"]) &&
            is_array($result->data["published_ports_requested"])
        ) {
            foreach ($result->data["published_ports_requested"] as $port) {
                $host =
                    $port["host_ip"] ?? null ? $port["host_ip"] . ":" : "host:";
                $target = $port["container"] ?? "?";
                $hostPort = $port["host_port"] ?? null ?: "auto";
                $lines[] = "Port mapping requested: $host$hostPort -> $target";
            }
        }
        if (
            ($result->data["pasta_auto_publish_requested"] ?? false) &&
            !($result->data["pasta_auto_publish_active"] ?? false)
        ) {
            $lines[] =
                "Port access: selected network uses explicit/image mappings and its container address; pasta auto-forward is inactive";
        }
        if (
            ($result->data["operation"] ?? "") !== "network-create" &&
            isset($result->data["requested"]) &&
            is_array($result->data["requested"])
        ) {
            foreach ($result->data["requested"] as $key => $value) {
                if ($value === null || $value === [] || $value === "") {
                    continue;
                }
                $lines[] =
                    "Requested " .
                    str_replace("_", " ", $key) .
                    ": " .
                    (is_array($value)
                        ? json_encode($value, JSON_UNESCAPED_SLASHES)
                        : (string) $value);
            }
        }

        if (
            $result->stdout !== "" &&
            in_array(
                $result->data["operation"] ?? "",
                ["exec", "wait-ready", "image-pull"],
                true,
            )
        ) {
            $lines[] = rtrim($result->stdout, "\r\n");
        }
        if (
            ($result->data["operation"] ?? "") === "build" &&
            $verbose &&
            $result->stdout !== ""
        ) {
            $lines[] = rtrim($result->stdout, "\r\n");
        }

        return implode("\n", $lines) . "\n";
    }
}
