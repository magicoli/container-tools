<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;
use Magicoli\ContainerTools\Container\ImageBuildDefinition;
use Magicoli\ContainerTools\Container\NetworkAttachment;
use Magicoli\ContainerTools\Container\NetworkDefinition;
use Magicoli\ContainerTools\Container\PortForward;

function podman_api(array $responses, ?array &$history = null): Podman
{
    $stack = HandlerStack::create(new MockHandler($responses));
    if ($history !== null) {
        $history = [];
        $stack->push(Middleware::history($history));
    }
    return new Podman(httpClient: new Client(['base_uri' => 'http://podman.test/', 'handler' => $stack, 'http_errors' => false]));
}

function api_version(): Response
{
    return new Response(200, [], json_encode(['ApiVersion' => '1.41', 'MinAPIVersion' => '1.24'], JSON_THROW_ON_ERROR));
}

function container_inspection(string $status = 'created'): Response
{
    return new Response(200, [], json_encode([
        'Id' => str_repeat('a', 64),
        'Name' => '/sample',
        'Config' => ['Hostname' => 'sample', 'Image' => 'alpine:3.24', 'Cmd' => ['php-fpm', '-F']],
        'State' => ['Status' => $status],
    ], JSON_THROW_ON_ERROR));
}

function image_inspection(array $ports = [], string $id = 'sha256:image-id'): Response
{
    return new Response(200, [], json_encode([
        'Id' => $id,
        'Config' => ['ExposedPorts' => (object) array_fill_keys($ports, new stdClass())],
    ], JSON_THROW_ON_ERROR));
}

test('create returns container inspection', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        image_inspection(),
        image_inspection(),
        new Response(201, [], json_encode(['Id' => str_repeat('a', 64)], JSON_THROW_ON_ERROR)),
        container_inspection(),
    ], $history);

    $result = $podman->create(new ContainerDefinition('sample', 'alpine:3.24', ['test.caller' => 'unit'], '512m', ['php-fpm', '-F']));

    expect($result->status)->toBe(0);
    expect($result->data['container'])->toMatchArray(['id' => str_repeat('a', 64), 'name' => 'sample', 'image' => 'alpine:3.24', 'hostname' => 'sample', 'status' => 'created']);
    expect($result->data['container']['api']['Config']['Cmd'])->toBe(['php-fpm', '-F']);
    expect((string) $history[3]['request']->getUri())->toContain('/v1.41/containers/create?name=sample');
    $body = json_decode((string) $history[3]['request']->getBody(), true);
    expect($body)->toMatchArray([
        'Image' => 'alpine:3.24',
        'Hostname' => 'sample',
        'OpenStdin' => true,
        'Tty' => true,
        'Labels' => ['test.caller' => 'unit'],
        'Cmd' => ['php-fpm', '-F'],
        'HostConfig' => ['Memory' => 536870912],
    ]);
});

test('build uploads context and options', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        new Response(404, [], '{"message":"no such image"}'),
        new Response(200, [], "{\"stream\":\"STEP 1/1: FROM alpine\\n\"}\n{\"aux\":{\"ID\":\"sha256:image-id\"}}\n"),
    ], $history);
    $context = dirname(__DIR__, 2) . '/examples/caddy';

    $result = $podman->build(new ImageBuildDefinition(
        $context . '/alpine/Containerfile',
        'localhost/test-image:latest',
        $context,
        'docker.io/library/debian:12-slim',
        true,
    ));

    expect($result->status)->toBe(0);
    expect($result->data)->toMatchArray([
        'operation' => 'build',
        'summary' => 'New image built without cache',
        'image' => 'localhost/test-image:latest',
        'from' => 'docker.io/library/debian:12-slim',
        'no_cache' => true,
    ]);
    expect((string) $history[2]['request']->getUri())->toContain('/v1.41/build?');
    parse_str((string) $history[2]['request']->getUri()->getQuery(), $query);
    expect($query)->toMatchArray([
        'dockerfile' => 'alpine/Containerfile',
        't' => 'localhost/test-image:latest',
        'from' => 'docker.io/library/debian:12-slim',
        'nocache' => 'true',
    ]);
    expect($history[2]['request']->getHeaderLine('Content-Type'))->toBe('application/x-tar');
});

test('build reports unchanged image', function () {
    $context = dirname(__DIR__, 2) . '/examples/caddy';
    $podman = podman_api([
        api_version(),
        image_inspection([], 'sha256:same-id'),
        new Response(200, [], "{\"stream\":\"STEP 1/1: FROM alpine\\n\"}\n{\"stream\":\"--> Using cache same-id\\n\"}\n{\"aux\":{\"ID\":\"sha256:same-id\"}}\n"),
    ]);

    $result = $podman->build(new ImageBuildDefinition($context . '/alpine/Containerfile', 'localhost/existing:latest', $context));

    expect($result->data['summary'])->toBe('Image unchanged (1/1 build steps reused from cache)');
});

test('build reports uncached rebuild', function () {
    $context = dirname(__DIR__, 2) . '/examples/caddy';
    $podman = podman_api([
        api_version(),
        image_inspection([], 'sha256:old-id'),
        new Response(200, [], "{\"stream\":\"STEP 1/1: FROM alpine\\n\"}\n{\"aux\":{\"ID\":\"sha256:new-id\"}}\n"),
    ]);

    $result = $podman->build(new ImageBuildDefinition($context . '/alpine/Containerfile', 'localhost/existing:latest', $context, noCache: true));

    expect($result->data['summary'])->toBe('Existing image rebuilt without cache');
});

test('build rejects files outside context', function () {
    $podman = podman_api([api_version()]);
    $context = dirname(__DIR__, 2) . '/examples/caddy';
    $result = $podman->build(new ImageBuildDefinition(__FILE__, 'localhost/invalid:latest', $context));

    expect($result->status)->toBe(2);
    expect($result->stderr)->toContain('inside the build context');
});

test('build reports stream errors', function () {
    $podman = podman_api([
        api_version(),
        new Response(404, [], '{"message":"no such image"}'),
        new Response(200, [], "{\"stream\":\"Step 1/1\\n\"}\n{\"errorDetail\":{\"message\":\"package install failed\"},\"error\":\"package install failed\"}\n"),
    ]);
    $context = dirname(__DIR__, 2) . '/examples/caddy';

    $result = $podman->build(new ImageBuildDefinition($context . '/alpine/Containerfile', 'localhost/broken:latest', $context));

    expect($result->status)->toBe(1);
    expect($result->stderr)->toContain('package install failed');
    expect($result->data['summary'])->toBe('Image build failed');
});

test('create keeps image command by default', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        image_inspection(),
        image_inspection(),
        new Response(201, [], json_encode(['Id' => str_repeat('a', 64)], JSON_THROW_ON_ERROR)),
        container_inspection(),
    ], $history);

    expect($podman->create(new ContainerDefinition('sample', 'alpine:3.24'))->status)->toBe(0);
    $body = json_decode((string) $history[3]['request']->getBody(), true);
    expect($body)->not->toHaveKey('Cmd');
    expect($body)->not->toHaveKey('Entrypoint');
    expect($body)->toMatchArray(['OpenStdin' => true, 'Tty' => true]);
});

test('create pulls missing image', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        new Response(404, [], '{"message":"no such image"}'),
        new Response(200, [], "{\"status\":\"Pulling from library/debian\"}\n{\"status\":\"Download complete\"}\n"),
        image_inspection(),
        new Response(201, [], json_encode(['Id' => str_repeat('b', 64)], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([
            'Id' => str_repeat('b', 64),
            'Name' => '/sample',
            'Config' => ['Hostname' => 'sample', 'Image' => 'docker.io/library/debian:12-slim', 'Cmd' => ['bash']],
            'State' => ['Status' => 'created'],
        ], JSON_THROW_ON_ERROR)),
    ], $history);

    $result = $podman->create(new ContainerDefinition('sample', 'docker.io/library/debian:12-slim'));

    expect($result->status)->toBe(0);
    expect($result->data['image_pulled'])->toBeTrue();
    expect((string) $history[2]['request']->getUri())->toContain('/images/create?fromImage=docker.io%2Flibrary%2Fdebian%3A12-slim');
    $body = json_decode((string) $history[4]['request']->getBody(), true);
    expect($body)->toBe(['Image' => 'docker.io/library/debian:12-slim', 'OpenStdin' => true, 'Tty' => true, 'Hostname' => 'sample']);
});

test('start and stop report state', function () {
    $podman = podman_api([
        api_version(), new Response(204), container_inspection('running'),
    ]);
    $started = $podman->start('sample');
    expect($started->status)->toBe(0);
    expect($started->data['container']['status'])->toBe('running');

    $podman = podman_api([api_version(), new Response(204), container_inspection('exited')]);
    $stopped = $podman->stop('sample');
    expect($stopped->status)->toBe(0);
    expect($stopped->data['container']['status'])->toBe('exited');
});

test('exec returns output and status', function () {
    $multiplexed = "\x01\x00\x00\x00\x00\x00\x00\x05hello";
    $podman = podman_api([
        api_version(),
        new Response(201, [], json_encode(['Id' => 'exec-id'], JSON_THROW_ON_ERROR)),
        new Response(200, [], $multiplexed),
        new Response(200, [], json_encode(['ExitCode' => 0], JSON_THROW_ON_ERROR)),
    ]);

    $result = $podman->exec('sample', ['echo', 'hello']);
    expect($result->status)->toBe(0);
    expect($result->stdout)->toBe('hello');
    expect($result->data['exit_code'])->toBe(0);
});

test('copyTo streams a local file to the selected container path', function () {
    $source = tempnam(sys_get_temp_dir(), 'container-tools-copy-source-');
    file_put_contents($source, "setup payload\n");
    $requestDetails = [];
    $stack = HandlerStack::create(new MockHandler([api_version(), new Response(200, [], '{}')]));
    $stack->push(Middleware::mapRequest(function ($request) use (&$requestDetails) {
        $body = $request->getBody();
        $body->rewind();
        $requestDetails = [
            'uri' => (string) $request->getUri(),
            'content_type' => $request->getHeaderLine('Content-Type'),
            'body' => $body->getContents(),
        ];
        $body->rewind();
        return $request;
    }));
    $podman = new Podman(httpClient: new Client(['base_uri' => 'http://podman.test/', 'handler' => $stack, 'http_errors' => false]));

    try {
        $result = $podman->copyTo('sample', $source, '/tmp/setup.sh');

        expect($result->status)->toBe(0);
        expect($result->data)->toMatchArray([
            'operation' => 'copy',
            'summary' => 'Files copied to container',
            'container' => ['reference' => 'sample'],
            'destination' => '/tmp/setup.sh',
        ]);
        expect($requestDetails['uri'])->toContain('/v1.41/containers/sample/archive?path=%2Ftmp');
        expect($requestDetails['content_type'])->toBe('application/x-tar');
        $archivePath = tempnam(sys_get_temp_dir(), 'container-tools-copy-archive-');
        unlink($archivePath);
        $archivePath .= '.tar';
        file_put_contents($archivePath, $requestDetails['body']);
        $archive = new PharData($archivePath);
        expect($archive->offsetExists('setup.sh'))->toBeTrue();
        expect($archive['setup.sh']->getContent())->toBe("setup payload\n");
        unset($archive);
        unlink($archivePath);
    } finally {
        unlink($source);
    }
});

test('copyTo archives a directory under the requested destination name', function () {
    $directory = sys_get_temp_dir() . '/container-tools-directory-' . bin2hex(random_bytes(4));
    mkdir($directory . '/nested', 0777, true);
    file_put_contents($directory . '/nested/run.sh', "echo ready\n");
    $requestBody = '';
    $stack = HandlerStack::create(new MockHandler([api_version(), new Response(200, [], '{}')]));
    $stack->push(Middleware::mapRequest(function ($request) use (&$requestBody) {
        $body = $request->getBody();
        $body->rewind();
        $requestBody = $body->getContents();
        $body->rewind();
        return $request;
    }));
    $podman = new Podman(httpClient: new Client(['base_uri' => 'http://podman.test/', 'handler' => $stack, 'http_errors' => false]));
    $archivePath = tempnam(sys_get_temp_dir(), 'container-tools-directory-archive-');
    unlink($archivePath);
    $archivePath .= '.tar';

    try {
        $result = $podman->copyTo('sample', $directory, '/opt/service');
        expect($result->status)->toBe(0);
        file_put_contents($archivePath, $requestBody);
        $archive = new PharData($archivePath);
        expect($archive->offsetExists('service/nested/run.sh'))->toBeTrue();
        expect($archive['service/nested/run.sh']->getContent())->toBe("echo ready\n");
        unset($archive);
    } finally {
        @unlink($archivePath);
        @unlink($directory . '/nested/run.sh');
        @rmdir($directory . '/nested');
        @rmdir($directory);
    }
});

test('destroy removes container', function () {
    $podman = podman_api([api_version(), container_inspection('exited'), new Response(204)]);
    $result = $podman->destroy('sample');

    expect($result->status)->toBe(0);
    expect($result->data['summary'])->toBe('Container destroyed');
    expect($result->data['container']['name'])->toBe('sample');
});

test('missing container is reported', function () {
    $podman = podman_api([api_version(), new Response(404, [], json_encode(['message' => 'no such container'], JSON_THROW_ON_ERROR))]);
    $result = $podman->destroy('typo');

    expect($result->status)->toBe(404);
    expect($result->stderr)->toContain('no such container');
    expect($result->data['operation'])->toBe('destroy');
});

test('network create configures IPAM', function () {
    $history = [];
    $podman = podman_api([api_version(), new Response(201, [], '{"Id":"network-id","Warning":""}')], $history);

    $result = $podman->createNetwork(new NetworkDefinition(
        'lan-dhcp', 'macvlan', 'enp3s0', 'dhcp', ipv6Enabled: true,
    ));

    expect($result->status)->toBe(0);
    expect($result->data['summary'])->toBe('Network created');
    $request = json_decode((string) $history[1]['request']->getBody(), true);
    expect($request)->toMatchArray([
        'Name' => 'lan-dhcp', 'Driver' => 'macvlan', 'EnableIPv6' => true,
        'IPAM' => ['Driver' => 'dhcp', 'Config' => []],
        'Options' => ['parent' => 'enp3s0'],
    ]);

    $podman = podman_api([api_version(), new Response(201, [], '{"Id":"network-id"}')], $history);
    $podman->createNetwork(new NetworkDefinition(
        'lan-static', 'macvlan', 'enp3s0', 'host-local', '192.0.2.0/24', '192.0.2.1', '2001:db8:1::/64', '2001:db8:1::1', true,
    ));
    $request = json_decode((string) $history[1]['request']->getBody(), true);
    expect($request['IPAM']['Config'])->toBe([
        ['Subnet' => '192.0.2.0/24', 'Gateway' => '192.0.2.1'],
        ['Subnet' => '2001:db8:1::/64', 'Gateway' => '2001:db8:1::1'],
    ]);
});

test('create assigns static IP addresses', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        new Response(200, [], '{"Name":"lan-static","Driver":"macvlan"}'),
        image_inspection(),
        image_inspection(),
        new Response(201, [], json_encode(['Id' => str_repeat('c', 64)], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([
            'Id' => str_repeat('c', 64), 'Name' => '/fixed-node',
            'Config' => ['Hostname' => 'fixed-node', 'Image' => 'alpine:3.24'],
            'State' => ['Status' => 'created'],
            'NetworkSettings' => ['Networks' => ['lan-static' => ['IPAddress' => '192.0.2.22', 'GlobalIPv6Address' => '2001:db8:1::22']]],
        ], JSON_THROW_ON_ERROR)),
    ], $history);

    $result = $podman->create(new ContainerDefinition(
        name: 'fixed-node', image: 'alpine:3.24', network: new NetworkAttachment('lan-static', '192.0.2.22', '2001:db8:1::22'),
    ));

    expect($result->status)->toBe(0);
    expect($result->data['container']['networks']['lan-static'])->toMatchArray(['ipv4' => '192.0.2.22', 'ipv6' => '2001:db8:1::22']);
    $body = json_decode((string) $history[4]['request']->getBody(), true);
    expect($body['HostConfig']['NetworkMode'])->toBe('lan-static');
    expect($body['NetworkingConfig']['EndpointsConfig']['lan-static']['IPAMConfig'])->toBe([
        'IPv4Address' => '192.0.2.22', 'IPv6Address' => '2001:db8:1::22',
    ]);
});

test('published ports appear in result', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        image_inspection(),
        image_inspection(['80/tcp']),
        new Response(201, [], json_encode(['Id' => str_repeat('d', 64)], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([
            'Id' => str_repeat('d', 64), 'Name' => '/web',
            'Config' => ['Hostname' => 'web', 'Image' => 'nginx:alpine'], 'State' => ['Status' => 'created'],
            'NetworkSettings' => ['Ports' => ['80/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '8080']]]],
        ], JSON_THROW_ON_ERROR)),
    ], $history);

    $podman->create(new ContainerDefinition('web', 'nginx:alpine', portForwards: [new PortForward(8080, 80, hostIp: '127.0.0.1')]));
    $body = json_decode((string) $history[3]['request']->getBody(), true);
    expect($body['HostConfig']['PortBindings']['80/tcp'])->toBe([['HostIp' => '127.0.0.1', 'HostPort' => '8080']]);
});

test('EXPOSE mappings accept overrides', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        image_inspection(),
        image_inspection(['22/tcp', '80/tcp', '443/tcp']),
        new Response(201, [], json_encode(['Id' => str_repeat('e', 64)], JSON_THROW_ON_ERROR)),
        container_inspection(),
    ], $history);

    $podman->create(new ContainerDefinition('caddy', 'localhost/caddy:latest', portForwards: [
        new PortForward(8022, 22), new PortForward(8080, 80), new PortForward(8443, 443), new PortForward(9000, 9000),
    ]));
    $body = json_decode((string) $history[3]['request']->getBody(), true);

    expect($body['HostConfig']['PortBindings'])->toBe([
        '22/tcp' => [['HostPort' => '8022']],
        '80/tcp' => [['HostPort' => '8080']],
        '443/tcp' => [['HostPort' => '8443']],
        '9000/tcp' => [['HostPort' => '9000']],
    ]);
});

test('explicit port replaces image mapping', function () {
    $history = [];
    $podman = podman_api([
        api_version(), image_inspection(), image_inspection(['8000/tcp']),
        new Response(201, [], json_encode(['Id' => str_repeat('f', 64)], JSON_THROW_ON_ERROR)),
        container_inspection(),
    ], $history);

    $podman->create(new ContainerDefinition('web', 'localhost/web:latest', portForwards: [new PortForward(8001, 8000)]));
    $body = json_decode((string) $history[3]['request']->getBody(), true);

    expect($body['HostConfig']['PortBindings'])->toBe(['8000/tcp' => [['HostPort' => '8001']]]);
});

test('explicit port adds image mapping', function () {
    $history = [];
    $podman = podman_api([
        api_version(), image_inspection(), image_inspection(['8000/tcp']),
        new Response(201, [], json_encode(['Id' => str_repeat('f', 64)], JSON_THROW_ON_ERROR)),
        container_inspection(),
    ], $history);

    $podman->create(new ContainerDefinition('web', 'localhost/web:latest', portForwards: [new PortForward(8001, 8001)]));
    $body = json_decode((string) $history[3]['request']->getBody(), true);

    expect($body['HostConfig']['PortBindings'])->toBe([
        '8000/tcp' => [[]],
        '8001/tcp' => [['HostPort' => '8001']],
    ]);
});

test('pasta configures runtime forwarding', function () {
    $history = [];
    $podman = podman_api([
        api_version(), image_inspection(), image_inspection(['8080/tcp']),
        new Response(201, [], json_encode(['Id' => str_repeat('f', 64)], JSON_THROW_ON_ERROR)),
        container_inspection(),
    ], $history);

    $podman->create(new ContainerDefinition('web', 'localhost/web:latest', pastaAutoPublish: true));
    $body = json_decode((string) $history[3]['request']->getBody(), true);

    expect($body['HostConfig']['NetworkMode'])->toBe('pasta:-t,auto,-u,auto,-T,auto,-U,auto');
    expect($body['HostConfig'])->not->toHaveKey('PortBindings');
});

test('pasta keeps explicit mappings', function () {
    $history = [];
    $podman = podman_api([
        api_version(), image_inspection(), image_inspection(['80/tcp']),
        new Response(201, [], json_encode(['Id' => str_repeat('f', 64)], JSON_THROW_ON_ERROR)),
        container_inspection(),
    ], $history);

    $podman->create(new ContainerDefinition('web', 'localhost/web:latest', portForwards: [new PortForward(8080, 80)], pastaAutoPublish: true));
    $body = json_decode((string) $history[3]['request']->getBody(), true);

    expect($body['HostConfig']['NetworkMode'])->toBe('pasta:-t,auto,-u,auto,-T,auto,-U,auto');
    expect($body['HostConfig']['PortBindings'])->toBe(['80/tcp' => [['HostPort' => '8080']]]);
});

test('direct networks allow published ports', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        new Response(200, [], '{"Name":"lan","Driver":"macvlan"}'),
        image_inspection(),
        image_inspection(['80/tcp']),
        new Response(201, [], json_encode(['Id' => str_repeat('b', 64)], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{}'),
        new Response(200, [], json_encode([
            'Id' => str_repeat('b', 64), 'Name' => '/web',
            'Config' => ['Hostname' => 'web', 'Image' => 'nginx:alpine'], 'State' => ['Status' => 'created'],
            'NetworkSettings' => ['Networks' => ['lan' => ['IPAddress' => '192.0.2.22', 'GlobalIPv6Address' => '2001:db8::22']]],
        ], JSON_THROW_ON_ERROR)),
    ], $history);
    $result = $podman->create(new ContainerDefinition(
        name: 'web', image: 'nginx:alpine', portForwards: [new PortForward(8080, 80)],
        network: new NetworkAttachment('lan', '192.0.2.22', '2001:db8::22'),
        pastaAutoPublish: true,
    ));

    expect($result->status)->toBe(0);
    expect($result->data['pasta_auto_publish_requested'])->toBeTrue();
    expect($result->data['pasta_auto_publish_active'])->toBeFalse();
    $create = json_decode((string) $history[4]['request']->getBody(), true);
    expect($create['HostConfig'])->toMatchArray([
        'NetworkMode' => 'bridge',
        'PortBindings' => ['80/tcp' => [['HostPort' => '8080']]],
    ]);
    expect((string) $history[5]['request']->getUri())->toContain('/networks/lan/connect');
    expect(json_decode((string) $history[5]['request']->getBody(), true))->toBe([
        'Container' => str_repeat('b', 64),
        'EndpointConfig' => ['IPAMConfig' => ['IPv4Address' => '192.0.2.22', 'IPv6Address' => '2001:db8::22']],
    ]);
});

test('port parser accepts fixed and automatic ports', function () {
    $fixed = PortForward::parse('8080:80');
    expect($fixed->hostPort)->toBe(8080);
    expect($fixed->containerPort)->toBe(80);
    expect($fixed->protocol())->toBe('tcp');
    $automatic = PortForward::parse('53/udp');
    expect($automatic->hostPort)->toBeNull();
    expect($automatic->containerPort)->toBe(53);
    expect($automatic->protocol())->toBe('udp');
});

test('port parser expands lists and ranges', function () {
    $list = PortForward::parseList('8080:80,8022:22,8443:443');
    expect(array_map(static fn (PortForward $forward) => [$forward->hostPort, $forward->containerPort], $list))
        ->toBe([[8080, 80], [8022, 22], [8443, 443]]);

    $ranges = PortForward::parseList('8002-8100,9000-9100');
    expect($ranges)->toHaveCount(200);
    expect([$ranges[0]->hostPort, $ranges[0]->containerPort])->toBe([8002, 8002]);
    expect([$ranges[98]->hostPort, $ranges[98]->containerPort])->toBe([8100, 8100]);
    expect([$ranges[99]->hostPort, $ranges[99]->containerPort])->toBe([9000, 9000]);
    expect([$ranges[199]->hostPort, $ranges[199]->containerPort])->toBe([9100, 9100]);

    $automatic = PortForward::parseList(':80-82');
    expect(array_map(static fn (PortForward $forward) => [$forward->hostPort, $forward->containerPort], $automatic))
        ->toBe([[null, 80], [null, 81], [null, 82]]);
    expect(PortForward::parse('[::1]:8080:80')->hostIp)->toBe('::1');
});

test('port parser rejects mismatched ranges', function () {
    expect(fn () => PortForward::parseList('8080-8082:80-81'))->toThrow(InvalidArgumentException::class);
});
