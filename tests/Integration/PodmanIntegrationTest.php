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

function integration_name(string $caller): string
{
    $host = preg_replace('/[^a-z0-9-]+/', '-', strtolower(gethostname() ?: 'host'));

    return 'container-tools-test-' . trim((string) $host, '-') . '-' . $caller . '-' . bin2hex(random_bytes(4));
}

function integration_image(string $platform): string
{
    return 'localhost/container-tools-test:' . $platform;
}

function build_integration_image(Podman $podman, string $platform): void
{
    $directory = dirname(__DIR__, 2) . '/examples/caddy';
    $result = $podman->build(new ImageBuildDefinition(
        $directory . '/' . $platform . '/Containerfile',
        integration_image($platform),
        $directory,
        $platform === 'alpine' ? 'docker.io/library/caddy:2-alpine' : null,
    ));
    expect($result->status)->toBe(0, $result->stderr . $result->stdout);
}

function integration_unused_host_port(Podman $podman, string $probeName): int
{
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $port = random_int(20000, 45000);
        $hex = strtoupper(str_pad(dechex($port), 4, '0', STR_PAD_LEFT));
        $available = $podman->exec($probeName, ['sh', '-c', "! grep -qi ':$hex ' /proc/net/tcp /proc/net/tcp6"]);
        if ($available->succeeded()) {
            return $port;
        }
    }

    throw new RuntimeException('Could not choose an unused test port on the Podman host.');
}

function integration_host_probe(Podman $podman, string $name): void
{
    $result = $podman->create(new ContainerDefinition(
        $name,
        'docker.io/library/alpine:3.24',
        command: ['sh', '-c', 'sleep 300'],
        network: new \Magicoli\ContainerTools\Container\NetworkAttachment('host'),
    ));
    expect($result->status)->toBe(0, $result->stderr);
    expect($podman->start($name)->status)->toBe(0);
}

function integration_podman_is_rootless(): bool
{
    $process = @proc_open([getenv('PODMAN_BINARY') ?: 'podman', 'info', '--format', 'json'], [
        0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        return false;
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $info = $status === 0 ? json_decode($stdout, true) : null;

    return is_array($info) && (($info['host']['security']['rootless'] ?? $info['Host']['Security']['Rootless'] ?? false) === true);
}

describe('Podman integration', function () {
    test('PHP creates, starts, and checks readiness of a container', function () {
        $podman = integration_podman();
        $name = integration_name('php');
        $created = false;

        try {
            build_integration_image($podman, 'debian12');
            $result = $podman->create(new ContainerDefinition(
                $name,
                integration_image('debian12'),
                ['test.caller' => 'php'],
                portForwards: [new PortForward(null, 80, hostIp: '127.0.0.1')],
            ));
            expect($result->status)->toBe(0, $result->stderr);
            $created = true;
            expect($result->data['container']['name'])->toBe($name);
            expect($result->data['container']['id'])->toHaveLength(64);
            expect($result->data['container']['image'])->toBe(integration_image('debian12'));
            expect($result->data['container']['api']['Config']['OpenStdin'])->toBeTrue();
            expect($result->data['container']['api']['Config']['Tty'])->toBeTrue();
            expect(array_keys($result->data['container']['api']['Config']['ExposedPorts']))->toContain('22/tcp', '80/tcp', '443/tcp');
            $started = $podman->start($name);
            expect($started->status)->toBe(0);
            expect($started->data['container']['ports'])->toHaveCount(3);
            $httpPort = array_values(array_filter($started->data['container']['ports'], static fn (array $port) => $port['container'] === '80/tcp'))[0] ?? [];
            expect($httpPort)->toMatchArray([
                'container' => '80/tcp',
                'host_ip' => '127.0.0.1',
            ]);
            expect($httpPort['host_port'] ?? '')->not->toBe('');
            $probe = ['sh', '-c', 'wget -q -O /dev/null http://127.0.0.1/ && cat /proc/net/tcp /proc/net/tcp6 | grep -q ":0016 " && cat /proc/net/tcp /proc/net/tcp6 | grep -q ":0050 " && cat /proc/net/tcp /proc/net/tcp6 | grep -q ":01BB "'];
            $ready = $podman->waitUntilReady($name, $probe);
            expect($ready->status)->toBe(0, $ready->stderr . $ready->stdout);
            expect(trim($podman->exec($name, ['wget', '-q', '-O', '-', 'http://127.0.0.1/'])->stdout))->toBe('container-tools test image');
            expect(trim($podman->status($name)->stdout))->toBe('running');
            expect(trim($podman->exec($name, ['hostname'])->stdout))->toBe($name);
            expect($podman->stop($name)->status)->toBe(0);
            expect(trim($podman->status($name)->stdout))->toBe('exited');
            expect($podman->start($name)->status)->toBe(0);
        } finally {
            if ($created) {
                $podman->destroy($name);
            }
        }
    });

    test('PHP creates an instance using Compose runtime configuration', function () {
        $podman = integration_podman();
        $name = integration_name('compose');
        $created = false;

        try {
            $configuration = __DIR__ . '/../fixtures/compose-instance/compose.yaml';
            $created = true;
            $result = $podman->createFromCompose(new ComposeInstanceDefinition($configuration, 'instance', $name));
            expect($result->status)->toBe(0, $result->stderr . $result->stdout);
            $container = $result->data['container'];
            expect($container['name'])->toBe($name);
            expect($container['status'])->toBe('created');
            expect($container['api']['Config']['Image'])->toBe('docker.io/library/alpine:3.24');
            expect($container['api']['Config']['Env'])->toContain('CONTAINER_TOOLS_COMPOSE_TEST=enabled');
            expect($container['api']['Config']['Labels'])->toMatchArray(['test.configuration' => 'compose']);
            $instanceMount = array_values(array_filter(
                $container['api']['Mounts'],
                static fn (array $mount): bool => ($mount['Destination'] ?? null) === '/etc/container-tools-instance.txt',
            ));
            expect($instanceMount)->toHaveCount(1);
            expect($instanceMount[0]['Source'])->toBe(realpath(dirname($configuration) . '/instance.txt'));
            expect($instanceMount[0]['RW'])->toBeFalse();
            expect($container['api']['NetworkSettings']['Ports']['8080/tcp'][0]['HostIp'])->toBe('127.0.0.1');
            expect($container['api']['NetworkSettings']['Ports']['8080/tcp'][0]['HostPort'])->not->toBe('');
            expect($podman->start($name)->status)->toBe(0);
            expect(trim($podman->status($name)->stdout))->toBe('running');
            expect(trim($podman->exec($name, ['cat', '/etc/container-tools-instance.txt'])->stdout))->toBe('compose-instance-config');
        } finally {
            if ($created) {
                $podman->destroy($name);
            }
        }
    });

    test('Bash CLI creates and starts a Compose-configured instance', function () {
        $podman = integration_podman();
        $name = integration_name('compose-cli');
        $created = false;

        try {
            $created = true;
            $cli = dirname(__DIR__, 2) . '/bin/container-tools';
            $configuration = __DIR__ . '/../fixtures/compose-instance/compose.yaml';
            $process = proc_open([
                $cli,
                'create',
                '--name=' . $name,
                '--compose-file=' . $configuration,
                '--service=instance',
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            expect(is_resource($process))->toBeTrue('Could not start the container-tools CLI');
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            expect($status)->toBe(0, $stderr . $stdout);
            expect($stdout)->toContain('Name: ' . $name);
            expect($stdout)->toContain('Status: running');
            expect(trim($podman->exec($name, ['cat', '/etc/container-tools-instance.txt'])->stdout))->toBe('compose-instance-config');
        } finally {
            if ($created) {
                $podman->destroy($name);
            }
        }
    });

    test('image ports receive distinct automatic host mappings when containers overlap', function () {
        $podman = integration_podman();
        $first = integration_name('ports-a');
        $second = integration_name('ports-b');
        $created = [];

        try {
            build_integration_image($podman, 'debian12');
            foreach ([$first, $second] as $name) {
                $result = $podman->create(new ContainerDefinition($name, integration_image('debian12')));
                expect($result->status)->toBe(0, $result->stderr);
                $created[] = $name;
                expect($result->data['container']['api']['Config']['ExposedPorts'])->toHaveKeys(['22/tcp', '80/tcp', '443/tcp']);
                $started = $podman->start($name);
                expect($started->status)->toBe(0, $started->stderr);
                expect($started->data['container']['ports'])->toHaveCount(3);
            }

            $firstPorts = array_column($podman->inspect($first)->data['container']['ports'], 'host_port');
            $secondPorts = array_column($podman->inspect($second)->data['container']['ports'], 'host_port');
            expect($firstPorts)->not->toContain('');
            expect($secondPorts)->not->toContain('');
            expect(array_intersect($firstPorts, $secondPorts))->toBe([]);
        } finally {
            foreach ($created as $name) {
                $podman->destroy($name);
            }
        }
    });

    test('pasta forwards a port opened later by a controller command', function () {
        $podman = integration_podman();
        $probeName = integration_name('pasta-probe-controller');
        $targetName = integration_name('pasta-controller');
        $created = [];

        try {
            integration_host_probe($podman, $probeName);
            $created[] = $probeName;
            $port = integration_unused_host_port($podman, $probeName);
            $result = $podman->create(new ContainerDefinition(
                $targetName,
                'docker.io/library/alpine:3.24',
                command: ['sh', '-c', 'sleep 300'],
                pastaAutoPublish: true,
            ));
            expect($result->status)->toBe(0, $result->stderr);
            $created[] = $targetName;
            expect($podman->start($targetName)->status)->toBe(0);
            $started = $podman->exec($targetName, ['sh', '-c', "busybox httpd -f -p $port -h /tmp >/dev/null 2>&1 </dev/null &"]);
            expect($started->status)->toBe(0, $started->stderr);
            $ready = $podman->waitUntilReady($probeName, ['wget', '-q', '-O', '-', "http://127.0.0.1:$port/"], 10, 100_000);
            expect($ready->status)->toBe(0, $ready->stderr . $ready->stdout);
        } finally {
            foreach ($created as $name) {
                $podman->destroy($name);
            }
        }
    })->skip(!integration_podman_is_rootless(), 'pasta automatic port forwarding requires rootless Podman.');

    test('pasta forwards a port that begins listening inside a running container', function () {
        $podman = integration_podman();
        $probeName = integration_name('pasta-probe-container');
        $targetName = integration_name('pasta-container');
        $created = [];

        try {
            integration_host_probe($podman, $probeName);
            $created[] = $probeName;
            $port = integration_unused_host_port($podman, $probeName);
            $result = $podman->create(new ContainerDefinition(
                $targetName,
                'docker.io/library/alpine:3.24',
                command: ['sh', '-c', "(sleep 2; busybox httpd -f -p $port -h /tmp >/dev/null 2>&1) >/dev/null 2>&1 & exec sleep 300"],
                pastaAutoPublish: true,
            ));
            expect($result->status)->toBe(0, $result->stderr);
            $created[] = $targetName;
            expect($podman->start($targetName)->status)->toBe(0);
            $ready = $podman->waitUntilReady($probeName, ['wget', '-q', '-O', '-', "http://127.0.0.1:$port/"], 10, 100_000);
            expect($ready->status)->toBe(0, $ready->stderr . $ready->stdout);
        } finally {
            foreach ($created as $name) {
                $podman->destroy($name);
            }
        }
    })->skip(!integration_podman_is_rootless(), 'pasta automatic port forwarding requires rootless Podman.');

    test('Bash creates, starts, and checks readiness through the command adapter', function () {
        $name = integration_name('bash');
        $cli = dirname(__DIR__, 2) . '/bin/container-tools';
        $script = __DIR__ . '/podman-create-container.sh';
        $imageDirectory = dirname(__DIR__, 2) . '/examples/caddy';
        $process = proc_open(['bash', $script, $cli, $name, integration_image('alpine'), $imageDirectory . '/alpine/Containerfile', $imageDirectory, getenv('CONTAINER_CONNECTION') ?: ''], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        expect(is_resource($process))->toBeTrue('Could not start the Bash integration script');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        expect($status)->toBe(0, $stderr);
        expect((string) $stdout)->toContain('Name: ' . $name);
        expect((string) $stdout)->toContain('Status: running');
    });
});
