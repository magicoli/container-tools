<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\Driver\Podman;

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

test('container creation uses the compatible REST endpoint and returns API inspection details', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        new Response(200, [], '{"Id":"image-id"}'),
        new Response(201, [], json_encode(['Id' => str_repeat('a', 64)], JSON_THROW_ON_ERROR)),
        container_inspection(),
    ], $history);

    $result = $podman->create(new ContainerDefinition('sample', 'alpine:3.24', ['test.caller' => 'unit'], '512m', ['php-fpm', '-F']));

    expect($result->status)->toBe(0);
    expect($result->data['container'])->toMatchArray(['id' => str_repeat('a', 64), 'name' => 'sample', 'image' => 'alpine:3.24', 'hostname' => 'sample', 'status' => 'created']);
    expect($result->data['container']['api']['Config']['Cmd'])->toBe(['php-fpm', '-F']);
    expect((string) $history[2]['request']->getUri())->toContain('/v1.41/containers/create?name=sample');
    $body = json_decode((string) $history[2]['request']->getBody(), true);
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

test('container creation leaves the image command unchanged when no command was requested', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        new Response(200, [], '{"Id":"image-id"}'),
        new Response(201, [], json_encode(['Id' => str_repeat('a', 64)], JSON_THROW_ON_ERROR)),
        container_inspection(),
    ], $history);

    expect($podman->create(new ContainerDefinition('sample', 'alpine:3.24'))->status)->toBe(0);
    $body = json_decode((string) $history[2]['request']->getBody(), true);
    expect($body)->not->toHaveKey('Cmd');
    expect($body)->not->toHaveKey('Entrypoint');
    expect($body)->toMatchArray(['OpenStdin' => true, 'Tty' => true]);
});

test('container creation pulls an image that is not available yet', function () {
    $history = [];
    $podman = podman_api([
        api_version(),
        new Response(404, [], '{"message":"no such image"}'),
        new Response(200, [], "{\"status\":\"Pulling from library/debian\"}\n{\"status\":\"Download complete\"}\n"),
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
    $body = json_decode((string) $history[3]['request']->getBody(), true);
    expect($body)->toBe(['Image' => 'docker.io/library/debian:12-slim', 'OpenStdin' => true, 'Tty' => true, 'Hostname' => 'sample']);
});

test('start and stop use REST lifecycle operations and return the resulting state', function () {
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

test('exec returns the command output and exit code from the REST API', function () {
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

test('destroy inspects the target then removes it through the REST API', function () {
    $podman = podman_api([api_version(), container_inspection('exited'), new Response(204)]);
    $result = $podman->destroy('sample');

    expect($result->status)->toBe(0);
    expect($result->data['summary'])->toBe('Container destroyed');
    expect($result->data['container']['name'])->toBe('sample');
});

test('a missing container is reported from the REST API response', function () {
    $podman = podman_api([api_version(), new Response(404, [], json_encode(['message' => 'no such container'], JSON_THROW_ON_ERROR))]);
    $result = $podman->destroy('typo');

    expect($result->status)->toBe(404);
    expect($result->stderr)->toContain('no such container');
    expect($result->data['operation'])->toBe('destroy');
});
