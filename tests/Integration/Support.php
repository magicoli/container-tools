<?php

use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\ComposeInstanceDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;
use Magicoli\ContainerTools\Container\ImageBuildDefinition;
use Magicoli\ContainerTools\Container\PortForward;

function integration_podman(): Podman
{
    return new Podman();
}

function integration_compose_provider_available(): bool
{
    $process = @proc_open(
        integration_podman_command(["compose", "version"]),
        [
            0 => ["file", "/dev/null", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        return false;
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0;
}

function integration_name(string $caller): string
{
    $host = preg_replace(
        "/[^a-z0-9-]+/",
        "-",
        strtolower(gethostname() ?: "host"),
    );

    return "container-tools-test-" .
        trim((string) $host, "-") .
        "-" .
        $caller .
        "-" .
        bin2hex(random_bytes(4));
}

function integration_image(string $platform): string
{
    return "localhost/container-tools-test:" . $platform;
}

function build_integration_image(Podman $podman, string $platform): void
{
    $directory = dirname(__DIR__, 2) . "/examples/caddy";
    $result = $podman->build(
        new ImageBuildDefinition(
            $directory . "/" . $platform . "/Containerfile",
            integration_image($platform),
            $directory,
            $platform === "alpine" ? "docker.io/library/caddy:2-alpine" : null,
        ),
    );
    expect($result->status)->toBe(0, $result->stderr . $result->stdout);
}

function integration_unused_host_port(Podman $podman, string $probeName): int
{
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $port = random_int(20000, 45000);
        $hex = strtoupper(str_pad(dechex($port), 4, "0", STR_PAD_LEFT));
        $available = $podman->exec($probeName, [
            "sh",
            "-c",
            "! grep -qi ':$hex ' /proc/net/tcp /proc/net/tcp6",
        ]);
        if ($available->succeeded()) {
            return $port;
        }
    }

    throw new RuntimeException(
        "Could not choose an unused test port on the Podman host.",
    );
}

function integration_host_probe(Podman $podman, string $name): void
{
    $result = $podman->create(
        new ContainerDefinition(
            $name,
            "docker.io/library/alpine:3.24",
            command: ["sh", "-c", "sleep 300"],
            network: new \Magicoli\ContainerTools\Container\NetworkAttachment(
                "host",
            ),
        ),
    );
    expect($result->status)->toBe(0, $result->stderr);
    expect($podman->start($name)->status)->toBe(0);
}

function integration_podman_is_rootless(): bool
{
    $process = @proc_open(
        integration_podman_command(["info", "--format", "json"]),
        [
            0 => ["file", "/dev/null", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        return false;
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $info = $status === 0 ? json_decode($stdout, true) : null;

    return is_array($info) &&
        ($info["host"]["security"]["rootless"] ??
            ($info["Host"]["Security"]["Rootless"] ?? false)) ===
            true;
}

function integration_host_has_nc(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $command = ["sh", "-c", "command -v nc >/dev/null 2>&1"];
    $uri = integration_selected_connection_uri();
    if ($uri !== null && str_starts_with($uri, "ssh://")) {
        $parts = parse_url($uri);
        if (!is_array($parts) || empty($parts["host"])) {
            return $available = false;
        }

        $target =
            (isset($parts["user"]) ? $parts["user"] . "@" : "") .
            $parts["host"];
        $command = [
            "ssh",
            "-o",
            "BatchMode=yes",
            "-o",
            "ConnectTimeout=5",
            "-p",
            (string) ($parts["port"] ?? 22),
        ];
        $identity = integration_selected_connection_identity();
        if ($identity !== null) {
            array_push($command, "-i", $identity);
        }
        $command[] = $target;
        $command[] = "command -v nc >/dev/null 2>&1";
    }

    $process = @proc_open(
        $command,
        [
            0 => ["file", "/dev/null", "r"],
            1 => ["file", "/dev/null", "w"],
            2 => ["file", "/dev/null", "w"],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        return $available = false;
    }

    return $available = proc_close($process) === 0;
}

function integration_podman_command(array $arguments): array
{
    $command = [getenv("PODMAN_BINARY") ?: "podman"];
    $connection = getenv("CONTAINER_CONNECTION") ?: null;
    if ($connection !== null) {
        array_push($command, "--connection", $connection);
    }

    return [...$command, ...$arguments];
}

function integration_wait_for_host_port(
    int $port,
    int $timeoutSeconds = 10,
): bool {
    $uri = integration_selected_connection_uri();
    $target = null;
    if ($uri !== null && str_starts_with($uri, "ssh://")) {
        $parts = parse_url($uri);
        if (is_array($parts) && isset($parts["host"])) {
            $target =
                (isset($parts["user"]) ? $parts["user"] . "@" : "") .
                $parts["host"];
            $ssh = [
                "ssh",
                "-o",
                "BatchMode=yes",
                "-p",
                (string) ($parts["port"] ?? 22),
            ];
            $identity = integration_selected_connection_identity();
            if ($identity !== null) {
                array_push($ssh, "-i", $identity);
            }
            $ssh[] = $target;
            $ssh[] = "nc -z -w 2 127.0.0.1 $port";
            $probe = $ssh;
        }
    }
    $probe ??= ["nc", "-z", "-w", "2", "127.0.0.1", (string) $port];

    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $process = @proc_open(
            $probe,
            [
                0 => ["file", "/dev/null", "r"],
                1 => ["file", "/dev/null", "w"],
                2 => ["file", "/dev/null", "w"],
            ],
            $pipes,
        );
        if (is_resource($process) && proc_close($process) === 0) {
            return true;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);

    return false;
}

function integration_selected_connection_uri(): ?string
{
    $host = getenv("CONTAINER_HOST") ?: (getenv("DOCKER_HOST") ?: null);
    if ($host !== null) {
        return $host;
    }

    $process = @proc_open(
        [
            getenv("PODMAN_BINARY") ?: "podman",
            "system",
            "connection",
            "list",
            "--format",
            "json",
        ],
        [
            0 => ["file", "/dev/null", "r"],
            1 => ["pipe", "w"],
            2 => ["file", "/dev/null", "w"],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        return null;
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        return null;
    }
    $connections = json_decode((string) $stdout, true);
    $requested = getenv("CONTAINER_CONNECTION") ?: null;
    foreach (is_array($connections) ? $connections : [] as $connection) {
        if (
            ($requested !== null &&
                ($connection["Name"] ?? null) === $requested) ||
            ($requested === null && ($connection["Default"] ?? false) === true)
        ) {
            return is_string($connection["URI"] ?? null)
                ? $connection["URI"]
                : null;
        }
    }

    return null;
}

function integration_selected_connection_identity(): ?string
{
    $process = @proc_open(
        [
            getenv("PODMAN_BINARY") ?: "podman",
            "system",
            "connection",
            "list",
            "--format",
            "json",
        ],
        [
            0 => ["file", "/dev/null", "r"],
            1 => ["pipe", "w"],
            2 => ["file", "/dev/null", "w"],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        return null;
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        return null;
    }
    $connections = json_decode((string) $stdout, true);
    $requested = getenv("CONTAINER_CONNECTION") ?: null;
    foreach (is_array($connections) ? $connections : [] as $connection) {
        if (
            ($requested !== null &&
                ($connection["Name"] ?? null) === $requested) ||
            ($requested === null && ($connection["Default"] ?? false) === true)
        ) {
            return is_string($connection["Identity"] ?? null)
                ? $connection["Identity"]
                : null;
        }
    }

    return null;
}

function integration_podman_unavailable_reason(): ?string
{
    static $checked = false;
    static $reason = null;
    if ($checked) {
        return $reason;
    }
    $checked = true;

    try {
        new Podman();
    } catch (Throwable $exception) {
        $reason =
            "No reachable Podman engine for integration tests: " .
            trim($exception->getMessage());
    }

    return $reason;
}

function integration_skip_reason(string $feature = ""): ?string
{
    $reason = integration_podman_unavailable_reason();
    if ($reason !== null) {
        return $reason;
    }

    if ($feature === "compose" && !integration_compose_provider_available()) {
        return "Podman Compose provider is not available to this test runner.";
    }

    if ($feature === "pasta" && !integration_podman_is_rootless()) {
        return "pasta automatic port forwarding requires rootless Podman on the selected connection.";
    }

    if ($feature === "pasta" && !integration_host_has_nc()) {
        return "nc tool missing, install netcat to enable";
    }

    return null;
}
