<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Console;

use InvalidArgumentException;
use Magicoli\ContainerTools\Container\CommandResult;
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\ContainerManagerInterface;
use Magicoli\ContainerTools\Container\ImageBuildDefinition;
use Magicoli\ContainerTools\Container\NetworkAttachment;
use Magicoli\ContainerTools\Container\NetworkDefinition;
use Magicoli\ContainerTools\Container\PortForward;

final class ContainerToolsCommand
{
    public function __construct(private readonly ContainerManagerInterface $manager) {}

    public function run(array $arguments): int
    {
        [$arguments, $verbose, $quiet, $dryRun, $help] = $this->globalOptions($arguments);

        if ($help || in_array($arguments[0] ?? '', ['--help', '-h'], true)) {
            fwrite(STDOUT, self::usage());
            return 0;
        }

        if ($arguments === []) {
            fwrite(STDERR, self::usage());
            return 2;
        }

        if ($dryRun) {
            if (!$quiet) {
                $command = implode(' ', array_map('escapeshellarg', $arguments));
                fwrite(STDOUT, "Dry run: no action performed.\nWould run: $command\n");
            }
            return 0;
        }

        $action = array_shift($arguments) ?? '';
        try {
            $result = match ($action) {
                'image-pull' => count($arguments) === 1 ? $this->manager->pull($arguments[0]) : null,
                'build' => $this->build($arguments),
                'create' => $this->create($arguments),
                'network' => $this->network($arguments),
                'start' => count($arguments) === 1 ? $this->manager->start($arguments[0]) : null,
                'stop' => count($arguments) === 1 ? $this->manager->stop($arguments[0]) : null,
                'status' => count($arguments) === 1 ? $this->manager->status($arguments[0]) : null,
                'wait-ready' => $this->ready($arguments),
                'exec' => $this->execute($arguments),
                'destroy' => count($arguments) === 1 ? $this->manager->destroy($arguments[0]) : null,
                default => null,
            };
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, 'container-tools: ' . $exception->getMessage() . "\n");
            return 2;
        }

        if (!$result instanceof CommandResult) {
            fwrite(STDERR, self::usage());
            return 2;
        }
        if (!$quiet) {
            fwrite(STDOUT, $this->render($result, $verbose));
        }
        fwrite(STDERR, $result->stderr);

        return $result->status;
    }

    private static function usage(): string
    {
        return "usage: container-tools [-v|--verbose] [-q|--quiet] [-h|--help] [-d|--dry-run] [--container-connection NAME] image-pull IMAGE | build --file FILE --tag IMAGE [--from BASE_IMAGE] [--no-cache] [CONTEXT] | network create --name NAME [--driver bridge|macvlan|ipvlan] [--parent IFACE] [--ipam host-local|dhcp|none] [--ipv4-subnet CIDR] [--ipv4-gateway IP] [--ipv6-subnet CIDR] [--ipv6-gateway IP] [--ipv6] | network inspect|destroy NAME | create [--name NAME] [--image IMAGE] [--hostname HOSTNAME] [--memory SIZE] [--label KEY=VALUE ...] [--publish [HOST_PORT:]CONTAINER_PORT[/tcp|udp]] [--network NAME [--ip4 IP] [--ip6 IP]] [--no-start] [-- COMMAND...] | start NAME | stop NAME | status NAME | wait-ready NAME -- PROBE... | exec NAME -- COMMAND... | destroy NAME\n";
    }

    /** @return array{array, bool, bool, bool, bool} */
    private function globalOptions(array $arguments): array
    {
        $verbose = $quiet = $dryRun = $help = false;
        $filtered = [];
        $expectsValue = false;
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if ($argument === '--') {
                array_push($filtered, ...array_slice($arguments, $index));
                break;
            }
            if ($expectsValue) {
                $filtered[] = $argument;
                $expectsValue = false;
                continue;
            }
            if (in_array($argument, ['-v', '--verbose'], true)) {
                $verbose = true;
                continue;
            }
            if (in_array($argument, ['-q', '--quiet'], true)) {
                $quiet = true;
                continue;
            }
            if (in_array($argument, ['-d', '--dry-run'], true)) {
                $dryRun = true;
                continue;
            }
            if (in_array($argument, ['-h', '--help'], true)) {
                $help = true;
                continue;
            }
            if (in_array($argument, ['--name', '--image', '--hostname', '--memory', '--label', '--file', '--tag', '--from', '--publish', '--network', '--ip4', '--ip6', '--driver', '--parent', '--ipam', '--ipv4-subnet', '--ipv4-gateway', '--ipv6-subnet', '--ipv6-gateway'], true)) {
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
            if ($option === '--no-cache') {
                $noCache = true;
                continue;
            }
            if (str_starts_with($option, '--')) {
                $parts = explode('=', $option, 2);
                $name = $parts[0];
                $value = count($parts) === 2 ? $parts[1] : array_shift($arguments);
                if ($value === null || $value === '') {
                    return null;
                }
                match ($name) {
                    '--file' => $file = $value,
                    '--tag' => $tag = $value,
                    '--from' => $from = $value,
                    default => null,
                };
                if (!in_array($name, ['--file', '--tag', '--from'], true)) {
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
        return $this->manager->build(new ImageBuildDefinition($file, $tag, $context, $from, $noCache));
    }

    private function create(array $arguments): ?CommandResult
    {
        $name = $memory = $hostname = null;
        $image = getenv('CONTAINER_IMAGE') ?: ContainerDefinition::DEFAULT_IMAGE;
        $labels = [];
        $portForwards = [];
        $networkName = $ipv4 = $ipv6 = null;
        $start = true;
        $command = [];
        while ($arguments !== []) {
            $option = array_shift($arguments);
            if ($option === '--') {
                $command = $arguments;
                break;
            }
            if ($option === '--no-start') {
                $start = false;
                continue;
            }
            $parts = explode('=', $option, 2);
            $option = $parts[0];
            $value = count($parts) === 2 ? $parts[1] : array_shift($arguments);
            if ($value === null) {
                return null;
            }
            match ($option) {
                '--name' => $name = $value,
                '--image' => $image = $value,
                '--hostname' => $hostname = $value,
                '--memory' => $memory = $value,
                '--label' => $labels[] = $value,
                '--publish' => $portForwards[] = PortForward::parse($value),
                '--network' => $networkName = $value,
                '--ip4' => $ipv4 = $value,
                '--ip6' => $ipv6 = $value,
                default => null,
            };
            if (!in_array($option, ['--name', '--image', '--hostname', '--memory', '--label', '--publish', '--network', '--ip4', '--ip6'], true)) {
                return null;
            }
        }
        if (($ipv4 !== null || $ipv6 !== null) && $networkName === null) {
            return null;
        }
        $network = $networkName === null ? null : new NetworkAttachment($networkName, $ipv4, $ipv6);
        $created = $this->manager->create(new ContainerDefinition($name, $image, $labels, $memory, $command, $hostname, portForwards: $portForwards, network: $network));
        if (!$created->succeeded() || !$start) {
            return $created;
        }
        $container = $created->data['container'] ?? [];
        $target = $name ?? $container['name'] ?? $container['id'] ?? trim($created->stdout);
        if ($target === '') {
            return new CommandResult(1, $created->stdout, $created->stderr . "Podman did not return a container name or ID\n", $created->data);
        }
        $started = $this->manager->start($target);
        $startedContainer = $started->data['container'] ?? $container;
        $summary = !$started->succeeded()
            ? 'Container created but failed to start'
            : (($startedContainer['status'] ?? null) === 'running' ? 'Container created and started' : 'Container created; image command exited');

        return new CommandResult(
            $started->status,
            $created->stdout . $started->stdout,
            $created->stderr . $started->stderr,
            [
                ...$created->data,
                'summary' => $summary,
                'container' => $startedContainer,
            ],
        );
    }

    private function network(array $arguments): ?CommandResult
    {
        $action = array_shift($arguments);
        if ($action === 'inspect' || $action === 'destroy') {
            if (count($arguments) !== 1) {
                return null;
            }
            return $action === 'inspect'
                ? $this->manager->inspectNetwork($arguments[0])
                : $this->manager->destroyNetwork($arguments[0]);
        }
        if ($action !== 'create') {
            return null;
        }

        $values = [
            '--name' => null, '--driver' => 'bridge', '--parent' => null, '--ipam' => 'host-local',
            '--ipv4-subnet' => null, '--ipv4-gateway' => null, '--ipv6-subnet' => null, '--ipv6-gateway' => null,
        ];
        $ipv6 = false;
        while ($arguments !== []) {
            $option = array_shift($arguments);
            if ($option === '--ipv6') {
                $ipv6 = true;
                continue;
            }
            [$key, $value] = array_pad(explode('=', $option, 2), 2, null);
            if (!array_key_exists($key, $values)) {
                return null;
            }
            $value ??= array_shift($arguments);
            if ($value === null || $value === '') {
                return null;
            }
            $values[$key] = $value;
        }
        if ($values['--name'] === null) {
            return null;
        }
        $network = new NetworkDefinition(
            name: $values['--name'],
            driver: $values['--driver'],
            parent: $values['--parent'],
            ipamDriver: $values['--ipam'],
            ipv4Subnet: $values['--ipv4-subnet'],
            ipv4Gateway: $values['--ipv4-gateway'],
            ipv6Subnet: $values['--ipv6-subnet'],
            ipv6Gateway: $values['--ipv6-gateway'],
            ipv6Enabled: $ipv6 || $values['--ipv6-subnet'] !== null,
        );
        return $this->manager->createNetwork($network);
    }

    private function ready(array $arguments): ?CommandResult
    {
        $name = array_shift($arguments);
        if ($name === null || array_shift($arguments) !== '--' || $arguments === []) {
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
        if (array_shift($arguments) !== '--' || $arguments === []) {
            return null;
        }
        return $this->manager->exec($name, $arguments);
    }

    private function render(CommandResult $result, bool $verbose = false): string
    {
        if ($result->data === []) {
            return $result->stdout;
        }

        $lines = [];
        if (isset($result->data['summary'])) {
            $lines[] = $result->data['summary'];
        }
        foreach (($result->data['container'] ?? []) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if ($key === 'api') {
                if ($verbose) {
                    $lines[] = "API inspection:\n" . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                continue;
            }
            if ($key === 'ports') {
                foreach ($value as $port) {
                    $host = ($port['host_ip'] ?? '') !== '' ? $port['host_ip'] . ':' : '';
                    $lines[] = 'Published port: ' . ($host ?: 'host') . ($port['host_port'] ?? '?') . ' -> ' . ($port['container'] ?? '?');
                }
                continue;
            }
            if ($key === 'networks') {
                foreach ($value as $networkName => $addresses) {
                    $ips = array_filter([$addresses['ipv4'] ?? null, $addresses['ipv6'] ?? null]);
                    $lines[] = 'Network: ' . $networkName . (count($ips) > 0 ? ' (' . implode(', ', $ips) . ')' : '');
                }
                continue;
            }
            $label = match ($key) {
                'id' => 'ID',
                'short_id' => 'Short ID',
                'name' => 'Name',
                'image' => 'Image',
                'hostname' => 'Hostname',
                'status' => 'Status',
                'open_stdin' => 'Open stdin',
                'tty' => 'TTY',
                'reference' => 'Reference',
                default => ucfirst(str_replace('_', ' ', $key)),
            };
            $display = is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES));
            $lines[] = "$label: $display";
        }
        if (isset($result->data['network']) && is_array($result->data['network'])) {
            $network = $result->data['network'];
            foreach (['Name' => 'Name', 'name' => 'Name', 'Driver' => 'Driver', 'EnableIPv6' => 'IPv6 enabled', 'IPv6Enabled' => 'IPv6 enabled', 'Id' => 'ID', 'id' => 'ID'] as $key => $label) {
                if (isset($network[$key])) {
                    $lines[] = $label . ': ' . (is_bool($network[$key]) ? ($network[$key] ? 'yes' : 'no') : $network[$key]);
                }
            }
            $parent = $network['Options']['parent'] ?? $network['NetworkInterface'] ?? null;
            if ($parent !== null && $parent !== '') {
                $lines[] = 'Parent interface: ' . $parent;
            }
            $ipam = $network['IPAM'] ?? null;
            if (is_array($ipam)) {
                if (isset($ipam['Driver'])) {
                    $lines[] = 'IPAM: ' . $ipam['Driver'];
                }
                foreach (($ipam['Config'] ?? []) as $config) {
                    if (isset($config['Subnet'])) {
                        $lines[] = 'Subnet: ' . $config['Subnet'];
                    }
                    if (isset($config['Gateway'])) {
                        $lines[] = 'Gateway: ' . $config['Gateway'];
                    }
                }
            }
        }
        foreach (['image' => 'Image', 'pulled' => 'Pulled', 'image_pulled' => 'Image pulled', 'elapsed_seconds' => 'Elapsed seconds', 'exit_code' => 'Exit code'] as $key => $label) {
            if (array_key_exists($key, $result->data) && $result->data[$key] !== null) {
                $lines[] = "$label: " . (is_bool($result->data[$key]) ? ($result->data[$key] ? 'yes' : 'no') : (string) $result->data[$key]);
            }
        }
        if (($result->data['operation'] ?? '') !== 'network-create' && isset($result->data['requested']) && is_array($result->data['requested'])) {
            foreach ($result->data['requested'] as $key => $value) {
                if ($value === null || $value === [] || $value === '') {
                    continue;
                }
                $lines[] = 'Requested ' . str_replace('_', ' ', $key) . ': ' . (is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : (string) $value);
            }
        }

        if ($result->stdout !== '' && in_array($result->data['operation'] ?? '', ['exec', 'wait-ready', 'image-pull'], true)) {
            $lines[] = rtrim($result->stdout, "\r\n");
        }
        if (($result->data['operation'] ?? '') === 'build' && $verbose && $result->stdout !== '') {
            $lines[] = rtrim($result->stdout, "\r\n");
        }

        return implode("\n", $lines) . "\n";
    }

}
