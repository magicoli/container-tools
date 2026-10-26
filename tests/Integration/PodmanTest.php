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

test('Pasta forwards a UDP listener opened inside the container', function () {
    $podman = integration_podman();
    $probeName = integration_name('pasta-udp-probe');
    $targetName = integration_name('pasta-udp');
    $created = [];

    try {
        integration_host_probe($podman, $probeName);
        $created[] = $probeName;
        $port = integration_unused_host_port($podman, $probeName);
        $message = 'pasta-udp-' . bin2hex(random_bytes(6));
        $result = $podman->create(new ContainerDefinition(
            $targetName,
            'docker.io/library/alpine:3.24',
            command: ['sh', '-c', "busybox nc -u -l -p $port > /tmp/udp-request & exec sleep 300"],
            pastaAutoPublish: true,
        ));
        expect($result->status)->toBe(0, $result->stderr . $result->stdout);
        $created[] = $targetName;
        expect($podman->start($targetName)->status)->toBe(0);

        $hexPort = strtoupper(str_pad(dechex($port), 4, '0', STR_PAD_LEFT));
        $listening = false;
        $listenDeadline = microtime(true) + 5;
        do {
            $socket = $podman->exec($targetName, [
                'sh', '-c', "grep -qi ':$hexPort ' /proc/net/udp /proc/net/udp6",
            ]);
            if ($socket->succeeded()) {
                $listening = true;
                break;
            }
            usleep(250_000);
        } while (microtime(true) < $listenDeadline);
        expect($listening)->toBeTrue('The UDP listener did not bind inside the container.');

        $sent = $podman->exec($probeName, [
            'sh', '-c', "printf '%s' '$message' | busybox nc -u -w 1 127.0.0.1 $port",
        ]);
        expect($sent->status)->toBe(0, $sent->stderr . $sent->stdout);

        $received = '';
        $deadline = microtime(true) + 5;
        do {
            $read = $podman->exec($targetName, ['cat', '/tmp/udp-request']);
            if ($read->succeeded()) {
                $received = trim($read->stdout);
            }
            if ($received === $message) {
                break;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        expect($received)->toBe($message, 'The UDP datagram did not reach the listener through Pasta.');
    } finally {
        foreach ($created as $name) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason('pasta') !== null, integration_skip_reason('pasta') ?? '');

test('Pasta follows TCP listener changes without replacing the container', function () {
    $podman = integration_podman();
    $probeName = integration_name('pasta-change-probe');
    $targetName = integration_name('pasta-change');
    $created = [];

    try {
        integration_host_probe($podman, $probeName);
        $created[] = $probeName;
        $port = integration_unused_host_port($podman, $probeName);
        $result = $podman->create(new ContainerDefinition(
            $targetName,
            'docker.io/library/alpine:3.24',
            command: ['sh', '-c', 'exec sleep 300'],
            pastaAutoPublish: true,
        ));
        expect($result->status)->toBe(0, $result->stderr . $result->stdout);
        $created[] = $targetName;
        $containerId = $result->data['container']['id'];
        expect($podman->start($targetName)->status)->toBe(0);

        $startListener = "busybox nc -lk -p $port -e /bin/cat >/dev/null 2>&1 & echo \$! >/tmp/nc.pid";
        expect($podman->exec($targetName, ['sh', '-c', $startListener])->status)->toBe(0);
        expect(integration_wait_for_host_port($port))->toBeTrue('Pasta did not publish the new TCP listener.');

        $stopped = $podman->exec($targetName, ['sh', '-c', 'kill "$(cat /tmp/nc.pid)"']);
        expect($stopped->status)->toBe(0, $stopped->stderr . $stopped->stdout);
        $closed = false;
        $closeDeadline = microtime(true) + 10;
        do {
            $probe = $podman->exec($probeName, ['busybox', 'nc', '-z', '-w', '1', '127.0.0.1', (string) $port]);
            if (!$probe->succeeded()) {
                $closed = true;
                break;
            }
            usleep(250_000);
        } while (microtime(true) < $closeDeadline);
        expect($closed)->toBeTrue('Pasta kept forwarding after the TCP listener stopped.');

        expect($podman->exec($targetName, ['sh', '-c', $startListener])->status)->toBe(0);
        expect(integration_wait_for_host_port($port))->toBeTrue('Pasta did not republish the restarted TCP listener.');
        expect($podman->inspect($targetName)->data['container']['id'])->toBe($containerId);
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

test('publishes explicit TCP and UDP mappings', function () {
    $podman = integration_podman();
    $probeName = integration_name('ports-probe');
    $targetName = integration_name('ports-explicit');
    $created = [];

    try {
        integration_host_probe($podman, $probeName);
        $created[] = $probeName;
        $occupied = $podman->hostPorts()->data['ports'];
        $available = array_values(array_diff(range(20000, 45000), $occupied));
        expect(count($available))->toBeGreaterThan(1);
        $tcpHostPort = $available[0];
        $udpHostPort = $available[1];
        $tcpContainerPort = 19080;
        $udpContainerPort = 19081;
        $listener = sprintf(
            'busybox nc -l -p %d > /tmp/tcp-request & busybox nc -u -l -p %d > /tmp/udp-request & exec sleep 300',
            $tcpContainerPort,
            $udpContainerPort,
        );

        $result = $podman->create(new ContainerDefinition(
            $targetName,
            'docker.io/library/alpine:3.24',
            command: ['sh', '-c', $listener],
            portForwards: [
                new PortForward($tcpHostPort, $tcpContainerPort, 'tcp', '127.0.0.1'),
                new PortForward($udpHostPort, $udpContainerPort, 'udp', '127.0.0.1'),
            ],
        ));
        expect($result->status)->toBe(0, $result->stderr . $result->stdout);
        $created[] = $targetName;
        expect($podman->start($targetName)->status)->toBe(0);
        expect($podman->inspect($targetName)->data['container']['ports'])->toContain(
            ['container' => "$tcpContainerPort/tcp", 'host_ip' => '127.0.0.1', 'host_port' => (string) $tcpHostPort],
            ['container' => "$udpContainerPort/udp", 'host_ip' => '127.0.0.1', 'host_port' => (string) $udpHostPort],
        );

        expect($podman->exec($probeName, ['sh', '-c', "printf tcp-request | busybox nc -w 2 127.0.0.1 $tcpHostPort"])->status)->toBe(0);
        expect($podman->exec($probeName, ['sh', '-c', "printf udp-request | busybox nc -u -w 2 127.0.0.1 $udpHostPort"])->status)->toBe(0);
        expect(trim($podman->exec($targetName, ['cat', '/tmp/tcp-request'])->stdout))->toBe('tcp-request');
        expect(trim($podman->exec($targetName, ['cat', '/tmp/udp-request'])->stdout))->toBe('udp-request');
    } finally {
        foreach ($created as $name) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');

test('explicit TCP mapping reaches a listener started after container startup', function () {
    $podman = integration_podman();
    $probeName = integration_name('bridge-listener-probe');
    $targetName = integration_name('bridge-listener');
    $created = [];

    try {
        integration_host_probe($podman, $probeName);
        $created[] = $probeName;
        $port = integration_unused_host_port($podman, $probeName);
        $result = $podman->create(new ContainerDefinition(
            $targetName,
            'docker.io/library/alpine:3.24',
            command: ['sh', '-c', 'exec sleep 300'],
            portForwards: [new PortForward($port, $port, hostIp: '127.0.0.1')],
        ));
        expect($result->status)->toBe(0, $result->stderr . $result->stdout);
        $created[] = $targetName;
        $containerId = $result->data['container']['id'];
        expect($podman->start($targetName)->status)->toBe(0);
        $listener = "busybox nc -lk -p $port -e /bin/cat >/dev/null 2>&1 &";
        expect($podman->exec($targetName, ['sh', '-c', $listener])->status)->toBe(0);
        expect(integration_wait_for_host_port($port))->toBeTrue('The configured TCP mapping did not reach the late listener.');

        $message = 'bridge-' . bin2hex(random_bytes(6));
        $probe = $podman->exec($probeName, [
            'sh', '-c', "printf '%s' '$message' | busybox nc -w 2 127.0.0.1 $port",
        ]);
        expect($probe->status)->toBe(0, $probe->stderr . $probe->stdout);
        expect($podman->inspect($targetName)->data['container']['id'])->toBe($containerId);
    } finally {
        foreach ($created as $name) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');

test('rejects an occupied published TCP port', function () {
    $podman = integration_podman();
    $probeName = integration_name('occupied-port-probe');
    $firstName = integration_name('occupied-port-first');
    $secondName = integration_name('occupied-port-second');
    $created = [];

    try {
        integration_host_probe($podman, $probeName);
        $created[] = $probeName;
        $port = integration_unused_host_port($podman, $probeName);
        foreach ([$firstName, $secondName] as $name) {
            $result = $podman->create(new ContainerDefinition(
                $name,
                'docker.io/library/alpine:3.24',
                command: ['sh', '-c', 'exec sleep 300'],
                portForwards: [new PortForward($port, $port, hostIp: '127.0.0.1')],
            ));
            expect($result->status)->toBe(0, $result->stderr . $result->stdout);
            $created[] = $name;
            if ($name === $firstName) {
                expect($podman->start($name)->status)->toBe(0);
                continue;
            }
            $collision = $podman->start($name);
            expect($collision->succeeded())->toBeFalse('Podman accepted a host port already bound by another container.');
        }
    } finally {
        foreach ($created as $name) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason() !== null, integration_skip_reason() ?? '');

test('published TCP port accepts a connection from the test host', function () {
    $podman = integration_podman();
    $probeName = integration_name('external-port-probe');
    $targetName = integration_name('external-port');
    $host = trim((string) getenv('CONTAINER_TEST_EXTERNAL_HOST'));
    $created = [];

    try {
        integration_host_probe($podman, $probeName);
        $created[] = $probeName;
        $port = integration_unused_host_port($podman, $probeName);
        $result = $podman->create(new ContainerDefinition(
            $targetName,
            'docker.io/library/alpine:3.24',
            command: ['sh', '-c', "busybox nc -lk -p $port -e /bin/cat >/dev/null 2>&1"],
            portForwards: [new PortForward($port, $port)],
        ));
        expect($result->status)->toBe(0, $result->stderr . $result->stdout);
        $created[] = $targetName;
        expect($podman->start($targetName)->status)->toBe(0);
        expect(integration_wait_for_host_port($port))->toBeTrue('The Podman host could not reach the published port.');

        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$host]" : $host;
        $socket = @stream_socket_client("tcp://$address:$port", $errorCode, $errorMessage, 5);
        expect(is_resource($socket))->toBeTrue("Could not connect from the test host: $errorMessage ($errorCode)");
        stream_set_timeout($socket, 3);
        $message = 'external-' . bin2hex(random_bytes(6));
        expect(fwrite($socket, $message))->toBe(strlen($message));
        expect(fread($socket, strlen($message)))->toBe($message);
        fclose($socket);
    } finally {
        foreach ($created as $name) {
            $podman->destroy($name);
        }
    }
})->skip(integration_skip_reason('external') !== null, integration_skip_reason('external') ?? '');

test('local host receives a published TCP response', function () {
    $podman = integration_podman();
    $name = integration_name('local-tcp-echo');
    $port = random_int(30000, 45000);
    $created = false;

    try {
        $result = $podman->create(new ContainerDefinition(
            $name,
            'docker.io/library/alpine:3.24',
            command: ['sh', '-c', "busybox nc -lk -p $port -e /bin/cat >/dev/null 2>&1"],
            portForwards: [new PortForward($port, $port, 'tcp', '127.0.0.1')],
        ));
        expect($result->status)->toBe(0, $result->stderr . $result->stdout);
        $created = true;
        expect($podman->start($name)->status)->toBe(0);
        expect(integration_echo_response('tcp', '127.0.0.1', $port))
            ->toStartWith('port-probe-');
    } finally {
        if ($created) {
            $podman->destroy($name);
        }
    }
})->skip(
    integration_skip_reason() !== null || !integration_is_local_connection(),
    integration_skip_reason() ?? 'select a local Podman connection',
);

test('local host receives a published UDP response', function () {
    $podman = integration_podman();
    $name = integration_name('local-udp-echo');
    $port = random_int(30000, 45000);
    $created = false;

    try {
        $result = $podman->create(new ContainerDefinition(
            $name,
            'docker.io/library/alpine:3.24',
            command: ['sh', '-c', "busybox nc -u -l -p $port -e /bin/cat"],
            portForwards: [new PortForward($port, $port, 'udp', '127.0.0.1')],
        ));
        expect($result->status)->toBe(0, $result->stderr . $result->stdout);
        $created = true;
        expect($podman->start($name)->status)->toBe(0);
        sleep(2);
        $response = integration_echo_response('udp', '127.0.0.1', $port);
        expect($response)->toBeString('Local UDP listener did not return a response');
        expect($response)
            ->toStartWith('port-probe-');
    } finally {
        if ($created) {
            $podman->destroy($name);
        }
    }
})->skip(
    integration_skip_reason() !== null || !integration_is_local_connection(),
    integration_skip_reason() ?? 'select a local Podman connection',
);

test('remote host receives a published UDP response', function () {
    $podman = integration_podman();
    $name = integration_name('remote-udp-echo');
    $host = trim((string) getenv('CONTAINER_TEST_EXTERNAL_HOST'));
    $port = random_int(30000, 45000);
    $created = false;

    try {
        $result = $podman->create(new ContainerDefinition(
            $name,
            'docker.io/library/alpine:3.24',
            command: ['sh', '-c', "busybox nc -u -l -p $port -e /bin/cat"],
            portForwards: [new PortForward($port, $port, 'udp')],
        ));
        expect($result->status)->toBe(0, $result->stderr . $result->stdout);
        $created = true;
        expect($podman->start($name)->status)->toBe(0);
        sleep(2);
        $response = integration_echo_response('udp', $host, $port);
        expect($response)->toBeString("No UDP echo from $host:$port");
        expect($response)
            ->toStartWith('port-probe-');
    } finally {
        if ($created) {
            $podman->destroy($name);
        }
    }
})->skip(
    integration_skip_reason('external') !== null || integration_is_local_connection(),
    integration_skip_reason('external') ?? 'select a remote Podman connection',
);
});
