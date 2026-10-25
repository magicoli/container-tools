<?php

use Magicoli\ContainerTools\Container\ComposeInstanceDefinition;
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\PortForward;

require_once __DIR__ . '/Support.php';


describe('container lifecycle', function () {
test('PHP container readiness', function () {
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
    } finally {
        if ($created) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');

test('PHP container stops and restarts', function () {
    $podman = integration_podman();
    $name = integration_name('lifecycle');
    $created = false;

    try {
        $result = $podman->create(new ContainerDefinition(
            $name,
            'docker.io/library/alpine:3.24',
            command: ['sh', '-c', 'sleep 300'],
        ));
        expect($result->status)->toBe(0, $result->stderr . $result->stdout);
        $created = true;
        expect($podman->start($name)->status)->toBe(0);

        $stopped = $podman->stop($name);
        $afterStop = $podman->status($name);
        if (
            !$stopped->succeeded()
            && trim($afterStop->stdout) === 'exited'
            && str_contains($stopped->stderr, 'rootless netns: kill network process: permission denied')
        ) {
            $this->markTestSkipped('AppArmor denied pasta SIGTERM from Podman');
        }
        expect($stopped->status)->toBe(0, $stopped->stderr . $stopped->stdout);
        expect(trim($afterStop->stdout))->toBe('exited');
        expect($podman->start($name)->status)->toBe(0);
        expect(trim($podman->status($name)->stdout))->toBe('running');
    } finally {
        if ($created) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');

test('CLI container readiness', function () {
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
})->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');
});

describe('Compose instances', function () {
test('PHP Compose instance', function () {
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
            static fn (array $mount): bool => ($mount['Destination'] ?? null) === '/var/lib/container-tools-instance',
        ));
        expect($instanceMount)->toHaveCount(1);
        expect($instanceMount[0]['Type'])->toBe('volume');
        expect($container['api']['NetworkSettings']['Ports']['8080/tcp'][0]['HostIp'])->toBe('127.0.0.1');
        expect($container['api']['NetworkSettings']['Ports']['8080/tcp'][0]['HostPort'])->not->toBe('');
        expect($podman->start($name)->status)->toBe(0);
        expect(trim($podman->status($name)->stdout))->toBe('running');
        expect($podman->exec($name, ['test', '-d', '/var/lib/container-tools-instance'])->status)->toBe(0);
    } finally {
        if ($created) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason('compose') !== null, integration_skip_reason('compose') ?? '');

test('CLI Compose instance', function () {
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
        expect($podman->exec($name, ['test', '-d', '/var/lib/container-tools-instance'])->status)->toBe(0);
    } finally {
        if ($created) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason('compose') !== null, integration_skip_reason('compose') ?? '');
});

describe('port publishing', function () {
test('Pasta forwards controller port', function () {
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
        $started = $podman->exec($targetName, ['sh', '-c', "busybox nc -lk -p $port -e /bin/cat >/dev/null 2>&1 </dev/null &"]);
        expect($started->status)->toBe(0, $started->stderr);
        expect(integration_wait_for_host_port($port))->toBeTrue('The Podman host could not reach the dynamically forwarded port.');
    } finally {
        foreach ($created as $name) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason('pasta') !== null, integration_skip_reason('pasta') ?? '');

test('Pasta forwards container port', function () {
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
            command: ['sh', '-c', "(sleep 2; busybox nc -lk -p $port -e /bin/cat >/dev/null 2>&1) >/dev/null 2>&1 & exec sleep 300"],
            pastaAutoPublish: true,
        ));
        expect($result->status)->toBe(0, $result->stderr);
        $created[] = $targetName;
        expect($podman->start($targetName)->status)->toBe(0);
        expect(integration_wait_for_host_port($port))->toBeTrue('The Podman host could not reach the dynamically forwarded port.');
    } finally {
        foreach ($created as $name) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason('pasta') !== null, integration_skip_reason('pasta') ?? '');

test('EXPOSE host mappings', function () {
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
})->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');
});
