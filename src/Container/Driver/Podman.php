<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Magicoli\ContainerTools\Container\CommandResult;
use Magicoli\ContainerTools\Container\ComposeInstanceDefinition;
use Magicoli\ContainerTools\Container\ContainerDefinition;
use Magicoli\ContainerTools\Container\ContainerManagerInterface;
use Magicoli\ContainerTools\Container\ImageBuildDefinition;
use Magicoli\ContainerTools\Container\NetworkDefinition;
use Magicoli\ContainerTools\Container\Module\Compose\PodmanCompose;
use Magicoli\ContainerTools\Container\PortForward;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Podman adapter using its official Docker-compatible REST API. */
final class Podman implements ContainerManagerInterface
{
    private readonly PodmanConnection $connection;
    private readonly ClientInterface $http;
    private readonly string $apiVersion;
    private readonly ?string $connectionName;

    public function __construct(?string $connection = null, ?ClientInterface $httpClient = null)
    {
        $this->connectionName = $connection ?? (getenv('CONTAINER_CONNECTION') ?: null);
        if ($httpClient !== null) {
            $this->connection = new PodmanConnection();
            $this->http = $httpClient;
        } else {
            if (!extension_loaded('curl')) {
                throw new \RuntimeException('The PHP curl extension is required to connect to the Podman REST API.');
            }
            $this->connection = new PodmanConnection();
            $endpoint = $this->connection->open($this->connectionName);
            $this->http = new Client([
                'base_uri' => $endpoint['base_uri'],
                'http_errors' => false,
                'connect_timeout' => 10,
                'timeout' => 0,
                'curl' => $endpoint['curl'],
            ]);
        }
        $this->apiVersion = $this->negotiateApiVersion();
    }

    public function imageAvailable(string $image): bool
    {
        return $this->request('GET', $this->path('images/' . rawurlencode($image) . '/json'))->succeeded();
    }

    public function pull(string $image): CommandResult
    {
        if ($this->imageAvailable($image)) {
            return new CommandResult(0, data: ['operation' => 'image-pull', 'summary' => 'Image already available', 'image' => $image, 'pulled' => false]);
        }
        $result = $this->request('POST', $this->path('images/create') . '?fromImage=' . rawurlencode($image));
        return $result->withData(['operation' => 'image-pull', 'summary' => $result->succeeded() ? 'Image pulled' : 'Image pull failed', 'image' => $image, 'pulled' => true]);
    }

    public function build(ImageBuildDefinition $image): CommandResult
    {
        $file = realpath($image->file);
        $context = realpath($image->context);
        if ($file === false || !is_file($file)) {
            return new CommandResult(2, stderr: "Containerfile not found: {$image->file}\n", data: ['operation' => 'build']);
        }
        if ($context === false || !is_dir($context)) {
            return new CommandResult(2, stderr: "Build context not found: {$image->context}\n", data: ['operation' => 'build']);
        }
        if (!str_starts_with($file, rtrim($context, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return new CommandResult(2, stderr: "Containerfile must be inside the build context.\n", data: ['operation' => 'build']);
        }
        if ($image->tag === '') {
            return new CommandResult(2, stderr: "An image tag is required for a build.\n", data: ['operation' => 'build']);
        }

        $existingImage = $this->request('GET', $this->path('images/' . rawurlencode($image->tag) . '/json'));
        $existingImageData = $existingImage->succeeded() ? json_decode($existingImage->stdout, true) : null;
        $imageExisted = is_array($existingImageData) && isset($existingImageData['Id']);
        $previousImageId = $imageExisted ? $existingImageData['Id'] : null;

        $archivePath = tempnam(sys_get_temp_dir(), 'container-tools-build-');
        if ($archivePath === false) {
            return new CommandResult(1, stderr: "Could not create a temporary build archive.\n", data: ['operation' => 'build']);
        }
        unlink($archivePath);

        try {
            $dockerfile = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($context) + 1));
            $process = @proc_open(
                ['tar', '--exclude=.git', '-C', $context, '-cf', $archivePath . '.tar', '.'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                return new CommandResult(1, stderr: "Could not start tar to archive the build context.\n", data: ['operation' => 'build']);
            }
            $archiveOutput = stream_get_contents($pipes[1]);
            $archiveError = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $archiveStatus = proc_close($process);
            if ($archiveStatus !== 0) {
                return new CommandResult($archiveStatus, (string) $archiveOutput, (string) $archiveError, ['operation' => 'build']);
            }

            $query = [
                'dockerfile' => $dockerfile,
                't' => $image->tag,
                'nocache' => $image->noCache ? 'true' : 'false',
                'rm' => 'true',
            ];
            if ($image->from !== null && $image->from !== '') {
                $query['from'] = $image->from;
            }

            $stream = fopen($archivePath . '.tar', 'rb');
            if ($stream === false) {
                return new CommandResult(1, stderr: "Could not read the build archive.\n", data: ['operation' => 'build']);
            }
            try {
                $response = $this->http->request('POST', $this->path('build'), [
                    'query' => $query,
                    'headers' => ['Content-Type' => 'application/x-tar'],
                    'body' => $stream,
                ]);
            } catch (GuzzleException|Throwable $exception) {
                return new CommandResult(1, stderr: $exception->getMessage() . "\n", data: ['operation' => 'build', 'image' => $image->tag]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $result = $this->responseResult($response);
            $buildError = $this->buildError($result->stdout);
            if ($result->succeeded() && $buildError !== null) {
                return new CommandResult(1, $result->stdout, $buildError . "\n", [
                    'operation' => 'build',
                    'summary' => 'Image build failed',
                    'image' => $image->tag,
                    'from' => $image->from,
                    'no_cache' => $image->noCache,
                ]);
            }
            $buildStats = $this->buildStats($result->stdout);
            $summary = $result->succeeded()
                ? $this->buildSummary($image, $imageExisted, $previousImageId, $buildStats)
                : 'Image build failed';
            return new CommandResult($result->status, $result->stdout, $result->stderr, [
                'operation' => 'build',
                'summary' => $summary,
                'image' => $image->tag,
                'from' => $image->from,
                'no_cache' => $image->noCache,
                'image_existed' => $imageExisted,
                ...$buildStats,
            ]);
        } catch (\Throwable $exception) {
            return new CommandResult(1, stderr: $exception->getMessage() . "\n", data: ['operation' => 'build', 'image' => $image->tag]);
        } finally {
            if (is_file($archivePath . '.tar')) {
                unlink($archivePath . '.tar');
            }
        }
    }

    public function createNetwork(NetworkDefinition $network): CommandResult
    {
        $ipamConfig = [];
        if ($network->ipv4Subnet !== null) {
            $ipamConfig[] = array_filter(['Subnet' => $network->ipv4Subnet, 'Gateway' => $network->ipv4Gateway], static fn ($value) => $value !== null);
        }
        if ($network->ipv6Subnet !== null) {
            $ipamConfig[] = array_filter(['Subnet' => $network->ipv6Subnet, 'Gateway' => $network->ipv6Gateway], static fn ($value) => $value !== null);
        }

        $definition = [
            'Name' => $network->name,
            'Driver' => $network->driver,
            'EnableIPv6' => $network->ipv6Enabled,
            'IPAM' => ['Driver' => $network->ipamDriver, 'Config' => $ipamConfig],
        ];
        if ($network->parent !== null) {
            $definition['Options'] = ['parent' => $network->parent];
        }

        $result = $this->request('POST', $this->path('networks/create'), $definition);
        $response = json_decode($result->stdout, true);
        return $result->withData([
            'operation' => 'network-create',
            'summary' => $result->succeeded() ? 'Network created' : 'Network creation failed',
            'network' => is_array($response) ? [...$definition, ...$response] : $definition,
            'requested' => $definition,
        ]);
    }

    public function inspectNetwork(string $name): CommandResult
    {
        $result = $this->request('GET', $this->path('networks/' . rawurlencode($name)));
        $network = json_decode($result->stdout, true);
        return $result->withData([
            'operation' => 'network-inspect',
            'summary' => $result->succeeded() ? 'Network details' : 'Network inspection failed',
            'network' => is_array($network) ? $network : ['name' => $name],
        ]);
    }

    public function destroyNetwork(string $name): CommandResult
    {
        $result = $this->request('DELETE', $this->path('networks/' . rawurlencode($name)));
        return $result->withData([
            'operation' => 'network-destroy',
            'summary' => $result->succeeded() ? 'Network destroyed' : 'Network destruction failed',
            'network' => ['name' => $name],
        ]);
    }

    public function create(ContainerDefinition $container): CommandResult
    {
        $directNetwork = false;
        $isPastaMode = $container->network !== null && preg_match('/^pasta(?::|$)/', $container->network->name) === 1;
        $isHostMode = $container->network?->name === 'host';
        $pastaAutoActive = $container->pastaAutoPublish && ($container->network === null || $isPastaMode);
        $networkIsMode = $isPastaMode || $isHostMode || ($container->pastaAutoPublish && $container->network === null);
        if ($networkIsMode && $container->network !== null && ($container->network->ipv4 !== null || $container->network->ipv6 !== null)) {
            return new CommandResult(2, stderr: "Static IP addresses cannot be assigned with the {$container->network->name} network mode.\n", data: [
                'operation' => 'create',
                'summary' => 'Container creation failed because pasta does not accept a named-network IP assignment',
                'requested' => $this->requestedDefinition($container),
            ]);
        }
        if ($container->network !== null && !$networkIsMode) {
            $network = $this->inspectNetwork($container->network->name);
            if (!$network->succeeded()) {
                return $network->withData([
                    'operation' => 'create',
                    'summary' => 'Container creation failed because its network is unavailable',
                    'requested' => $this->requestedDefinition($container),
                ]);
            }
            $driver = $network->data['network']['Driver'] ?? null;
            $directNetwork = in_array($driver, ['macvlan', 'ipvlan'], true);
        }
        $image = $this->pull($container->image);
        if (!$image->succeeded()) {
            return $image->withData([
                'operation' => 'create',
                'summary' => 'Container creation failed because its image could not be obtained',
                'requested' => $this->requestedDefinition($container),
            ]);
        }

        $imageInspection = $this->request('GET', $this->path('images/' . rawurlencode($container->image) . '/json'));
        $imageData = $imageInspection->succeeded() ? json_decode($imageInspection->stdout, true) : null;
        $exposedPorts = is_array($imageData) ? ($imageData['Config']['ExposedPorts'] ?? []) : [];
        // pasta auto-forwarding is a runtime network mode. With any named
        // network, including macvlan/ipvlan, use ordinary port bindings instead.
        $portForwards = $this->effectivePortForwards(
            $container->publishExposedPorts && !$pastaAutoActive ? $exposedPorts : [],
            $container->portForwards,
        );
        $attachDirectNetworkAfterCreate = $directNetwork && $portForwards !== [];

        $query = $container->name === null ? '' : '?name=' . rawurlencode($container->name);
        $config = [
            'Image' => $container->image,
            'OpenStdin' => $container->openStdin,
            'Tty' => $container->tty,
        ];
        $hostname = $container->hostname ?? $container->name;
        if ($hostname !== null) {
            $config['Hostname'] = $hostname;
        }
        if ($container->labels !== []) {
            $config['Labels'] = $this->labels($container->labels);
        }
        if ($container->command !== []) {
            $config['Cmd'] = $container->command;
        }
        $hostConfig = [];
        if ($container->memory !== null && $container->memory !== '') {
            $hostConfig['Memory'] = $this->memoryBytes($container->memory);
        }
        if ($portForwards !== []) {
            $portBindings = [];
            foreach ($portForwards as $forward) {
                $binding = [];
                if ($forward->hostIp !== null) {
                    $binding['HostIp'] = $forward->hostIp;
                }
                if ($forward->hostPort !== null) {
                    $binding['HostPort'] = (string) $forward->hostPort;
                }
                $portBindings[$forward->containerPort . '/' . $forward->protocol()][] = (object) $binding;
            }
            $hostConfig['PortBindings'] = $portBindings;
        }
        if ($networkIsMode) {
            $mode = $container->network?->name ?? 'pasta';
            $hostConfig['NetworkMode'] = $pastaAutoActive
                ? 'pasta:-t,auto,-u,auto,-T,auto,-U,auto'
                : $mode;
        } elseif ($attachDirectNetworkAfterCreate) {
            $hostConfig['NetworkMode'] = 'bridge';
        } elseif ($container->network !== null) {
            $hostConfig['NetworkMode'] = $container->network->name;
        }
        if ($hostConfig !== []) {
            $config['HostConfig'] = $hostConfig;
        }
        if ($container->network !== null && !$networkIsMode && !$attachDirectNetworkAfterCreate) {
            $endpoint = [];
            $ipam = [];
            if ($container->network->ipv4 !== null) {
                $ipam['IPv4Address'] = $container->network->ipv4;
            }
            if ($container->network->ipv6 !== null) {
                $ipam['IPv6Address'] = $container->network->ipv6;
            }
            if ($ipam !== []) {
                $endpoint['IPAMConfig'] = $ipam;
            }
            $config['NetworkingConfig'] = ['EndpointsConfig' => [$container->network->name => (object) $endpoint]];
        }

        $result = $this->request('POST', $this->path('containers/create') . $query, $config);
        if (!$result->succeeded()) {
            return $result->withData(['operation' => 'create', 'summary' => 'Container creation failed', 'requested' => $this->requestedDefinition($container)]);
        }

        $created = json_decode($result->stdout, true);
        $id = is_array($created) ? ($created['Id'] ?? null) : null;
        if (!is_string($id) || $id === '') {
            return new CommandResult(1, $result->stdout, "Podman returned no container ID after creation.\n", ['operation' => 'create', 'summary' => 'Container was created but its ID was missing']);
        }
        if ($attachDirectNetworkAfterCreate) {
            $endpoint = [];
            $ipam = [];
            if ($container->network?->ipv4 !== null) {
                $ipam['IPv4Address'] = $container->network->ipv4;
            }
            if ($container->network?->ipv6 !== null) {
                $ipam['IPv6Address'] = $container->network->ipv6;
            }
            if ($ipam !== []) {
                $endpoint['IPAMConfig'] = $ipam;
            }
            $connected = $this->request('POST', $this->path('networks/' . rawurlencode($container->network->name) . '/connect'), [
                'Container' => $id,
                'EndpointConfig' => (object) $endpoint,
            ]);
            if (!$connected->succeeded()) {
                $this->request('DELETE', $this->path('containers/' . rawurlencode($id)) . '?force=true');
                return $connected->withData([
                    'operation' => 'create',
                    'summary' => 'Container created for published ports but could not be attached to its direct network',
                    'network' => $container->network->name,
                    'id' => $id,
                    'requested' => $this->requestedDefinition($container),
                ]);
            }
        }
        $inspection = $this->inspect($id);
        if (!$inspection->succeeded()) {
            return $inspection->withData(['operation' => 'create', 'summary' => 'Container was created but could not be inspected', 'id' => $id]);
        }
        return new CommandResult(0, $result->stdout, $result->stderr, [
            'operation' => 'create',
            'summary' => 'Container created',
            'container' => $inspection->data['container'],
            'requested' => $this->requestedDefinition($container),
            'image_pulled' => $image->data['pulled'] ?? false,
            'default_ports' => array_keys(is_array($exposedPorts) ? $exposedPorts : []),
            'published_ports_requested' => array_map(static fn ($forward) => [
                'container' => $forward->containerPort . '/' . $forward->protocol(),
                'host_ip' => $forward->hostIp,
                'host_port' => $forward->hostPort,
            ], $portForwards),
            'pasta_auto_publish_requested' => $container->pastaAutoPublish,
            'pasta_auto_publish_active' => $pastaAutoActive,
        ]);
    }

    public function createFromCompose(ComposeInstanceDefinition $instance): CommandResult
    {
        $project = 'container-tools-' . bin2hex(random_bytes(6));
        $compose = new PodmanCompose($this->connectionName);
        $up = $compose->up($instance->file, $instance->service, $project, $instance->name);
        if (!$up->succeeded()) {
            return $up->withData([
                'operation' => 'create',
                'summary' => 'Compose instance creation failed',
                'requested' => $this->requestedComposeInstance($instance),
                'compose_project' => $project,
            ]);
        }

        // `compose ps` only lists running containers in Docker Compose by default,
        // while this method deliberately creates the instance without starting it.
        // Query Podman's API for all containers so the behavior is provider-independent.
        $listed = $this->request('GET', $this->path('containers/json?all=true'));
        $containers = $listed->succeeded() ? json_decode($listed->stdout, true) : null;
        $serviceContainers = [];
        foreach (is_array($containers) ? $containers : [] as $row) {
            $labels = $row['Labels'] ?? [];
            if (($labels['com.docker.compose.project'] ?? null) !== $project
                || ($labels['com.docker.compose.service'] ?? null) !== $instance->service) {
                continue;
            }
            $candidate = $this->inspect($row['Id'] ?? '');
            $details = $candidate->data['container'] ?? null;
            if (is_array($details)) {
                $serviceContainers[] = $candidate;
            }
        }
        if (!$listed->succeeded() || !is_array($containers) || count($serviceContainers) !== 1) {
            return new CommandResult($listed->succeeded() ? 1 : $listed->status, $up->stdout . $listed->stdout, $up->stderr . $listed->stderr . (count($serviceContainers) > 1 ? "The Compose service created more than one container; container-tools manages one instance at a time.\n" : ''), [
                'operation' => 'create',
                'summary' => 'Compose instance was created but could not be identified as one container',
                'requested' => $this->requestedComposeInstance($instance),
                'compose_project' => $project,
            ]);
        }
        $inspection = $serviceContainers[0];

        return new CommandResult(0, $up->stdout . $listed->stdout, $up->stderr . $listed->stderr, [
            'operation' => 'create',
            'summary' => 'Container created from Compose configuration',
            'container' => $inspection->data['container'],
            'requested' => $this->requestedComposeInstance($instance),
            'compose_project' => $project,
        ]);
    }

    public function start(string $name): CommandResult
    {
        $result = $this->request('POST', $this->path('containers/' . rawurlencode($name) . '/start'));
        return $this->lifecycleResult($result, 'start', 'Container started', $name);
    }

    public function stop(string $name): CommandResult
    {
        $result = $this->request('POST', $this->path('containers/' . rawurlencode($name) . '/stop'));
        return $this->lifecycleResult($result, 'stop', 'Container stopped', $name);
    }

    public function inspect(string $name): CommandResult
    {
        $result = $this->request('GET', $this->path('containers/' . rawurlencode($name) . '/json'));
        if (!$result->succeeded()) {
            return $result->withData(['operation' => 'inspect', 'summary' => 'Container inspection failed', 'reference' => $name]);
        }
        $details = json_decode($result->stdout, true);
        if (!is_array($details)) {
            return new CommandResult(1, $result->stdout, "Podman returned invalid container inspection data.\n", ['operation' => 'inspect', 'reference' => $name]);
        }
        return $result->withData(['operation' => 'inspect', 'summary' => 'Container details', 'container' => $this->details($details)]);
    }

    public function status(string $name): CommandResult
    {
        $inspection = $this->inspect($name);
        if (!$inspection->succeeded()) {
            return $inspection->withData(['operation' => 'status', 'summary' => 'Could not read container status']);
        }
        $status = $inspection->data['container']['status'] ?? 'unknown';
        return new CommandResult(0, $status . "\n", data: ['operation' => 'status', 'summary' => 'Container status', 'container' => $inspection->data['container']]);
    }

    public function waitUntilReady(string $name, array $probe, int $timeoutSeconds = 30, int $intervalMicroseconds = 250_000): CommandResult
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $startedAt = microtime(true);
        do {
            $result = $this->exec($name, $probe);
            if ($result->succeeded()) {
                return $result->withData(['operation' => 'wait-ready', 'summary' => 'Container readiness probe passed', 'probe' => $probe, 'elapsed_seconds' => round(microtime(true) - $startedAt, 3)]);
            }
            if (microtime(true) >= $deadline) {
                return $result->withData(['operation' => 'wait-ready', 'summary' => 'Container readiness probe timed out', 'probe' => $probe, 'elapsed_seconds' => round(microtime(true) - $startedAt, 3)]);
            }
            usleep($intervalMicroseconds);
        } while (true);
    }

    public function exec(string $name, array $command): CommandResult
    {
        $created = $this->request('POST', $this->path('containers/' . rawurlencode($name) . '/exec'), [
            'AttachStdin' => false,
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Tty' => false,
            'Cmd' => array_values($command),
        ]);
        if (!$created->succeeded()) {
            return $created->withData(['operation' => 'exec', 'container' => ['reference' => $name]]);
        }
        $execId = json_decode($created->stdout, true)['Id'] ?? null;
        if (!is_string($execId) || $execId === '') {
            return new CommandResult(1, $created->stdout, "Podman returned no exec session ID.\n", ['operation' => 'exec']);
        }
        $started = $this->request('POST', $this->path('exec/' . rawurlencode($execId) . '/start'), ['Detach' => false, 'Tty' => false]);
        if (!$started->succeeded()) {
            return $started->withData(['operation' => 'exec', 'container' => ['reference' => $name]]);
        }
        [$stdout, $stderr] = $this->demultiplex($started->stdout);
        $inspection = $this->request('GET', $this->path('exec/' . rawurlencode($execId) . '/json'));
        $exitCode = json_decode($inspection->stdout, true)['ExitCode'] ?? 0;
        $exitCode = is_int($exitCode) ? $exitCode : 0;
        return new CommandResult($exitCode, $stdout, $stderr, ['operation' => 'exec', 'summary' => $exitCode === 0 ? 'Command executed in container' : 'Command failed in container', 'container' => ['reference' => $name], 'exit_code' => $exitCode]);
    }

    public function destroy(string $name): CommandResult
    {
        $inspection = $this->inspect($name);
        if (!$inspection->succeeded()) {
            return $inspection->withData(['operation' => 'destroy', 'summary' => 'Container was not destroyed because it could not be inspected']);
        }
        $result = $this->request('DELETE', $this->path('containers/' . rawurlencode($name) . '?force=true'));
        return $result->withData(['operation' => 'destroy', 'summary' => $result->succeeded() ? 'Container destroyed' : 'Container destruction failed', 'container' => $inspection->data['container']]);
    }

    private function request(string $method, string $path, ?array $json = null): CommandResult
    {
        try {
            $options = [];
            if ($json !== null) {
                $options['json'] = $json;
            }
            $response = $this->http->request($method, $path, $options);
            return $this->responseResult($response);
        } catch (GuzzleException|Throwable $exception) {
            return new CommandResult(1, '', $exception->getMessage() . "\n");
        }
    }

    private function responseResult(ResponseInterface $response): CommandResult
    {
        $body = (string) $response->getBody();
        $status = $response->getStatusCode();
        if (($status >= 200 && $status < 300) || $status === 304) {
            return new CommandResult(0, $body);
        }
        $document = json_decode($body, true);
        $message = is_array($document) ? ($document['message'] ?? $document['cause'][0]['message'] ?? null) : null;
        return new CommandResult($status, '', (is_string($message) ? $message : trim($body ?: $response->getReasonPhrase())) . "\n", ['http_status' => $status]);
    }

    private function buildError(string $output): ?string
    {
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $message = $record['errorDetail']['message'] ?? $record['error'] ?? null;
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }
        return null;
    }

    /** @return array{build_steps: int, cached_steps: int, image_id: ?string} */
    private function buildStats(string $output): array
    {
        $steps = $cached = 0;
        $imageId = null;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $message = $record['stream'] ?? '';
            if (is_string($message)) {
                if (preg_match('/\bSTEP\s+\d+\/(\d+)\s*:/i', $message, $match)) {
                    $steps = max($steps, (int) $match[1]);
                }
                if (str_contains(strtolower($message), 'using cache')) {
                    $cached++;
                }
            }
            $candidate = $record['aux']['ID'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $imageId = $candidate;
            }
        }

        return ['build_steps' => $steps, 'cached_steps' => $cached, 'image_id' => $imageId];
    }

    private function buildSummary(ImageBuildDefinition $image, bool $imageExisted, ?string $previousImageId, array $stats): string
    {
        $steps = $stats['build_steps'];
        $cached = $stats['cached_steps'];
        $allCached = $steps > 0 && $cached >= $steps;
        $sameImage = $previousImageId !== null && $stats['image_id'] !== null && $previousImageId === $stats['image_id'];

        if ($image->noCache) {
            return $imageExisted ? 'Existing image rebuilt without cache' : 'New image built without cache';
        }
        if ($allCached && $imageExisted && $sameImage) {
            return "Image unchanged ($cached/$steps build steps reused from cache)";
        }
        if ($allCached && !$imageExisted) {
            return "New image tagged from cache ($cached/$steps build steps reused)";
        }

        $cacheDetail = $steps > 0 ? " ($cached/$steps build steps reused from cache)" : '';
        return ($imageExisted ? 'Existing image rebuilt' : 'New image built') . $cacheDetail;
    }

    /**
     * Image EXPOSE metadata is published to automatically selected host ports
     * by default. Explicit mappings replace the automatic mapping for the same
     * container port and protocol, while unrelated exposed ports remain.
     *
     * @return list<\Magicoli\ContainerTools\Container\PortForward>
     */
    private function effectivePortForwards(array $exposedPorts, array $requested): array
    {
        $forwards = [];
        foreach (array_keys($exposedPorts) as $exposed) {
            if (!is_string($exposed) || !preg_match('/^(\d+)\/(tcp|udp)$/i', $exposed, $match)) {
                continue;
            }
            $forwards[] = new PortForward(null, (int) $match[1], strtolower($match[2]));
        }

        foreach ($requested as $forward) {
            $forwards = array_values(array_filter($forwards, static fn ($default) =>
                $default->containerPort !== $forward->containerPort || $default->protocol() !== $forward->protocol()
            ));
            $forwards[] = $forward;
        }

        return $forwards;
    }

    private function negotiateApiVersion(): string
    {
        $response = null;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $response = $this->request('GET', 'version');
            if ($response->succeeded()) {
                break;
            }
            usleep(100_000);
        }
        if (!$response->succeeded()) {
            throw new \RuntimeException('Could not connect to the Podman REST API: ' . trim($response->stderr));
        }
        $version = json_decode($response->stdout, true);
        $maximum = min('1.44', (string) ($version['ApiVersion'] ?? '1.40'));
        $minimum = (string) ($version['MinAPIVersion'] ?? '1.24');
        if (version_compare($maximum, $minimum, '<')) {
            throw new \RuntimeException('The container engine exposes no compatible Docker API version.');
        }
        return $maximum;
    }

    private function path(string $path): string
    {
        return 'v' . $this->apiVersion . '/' . ltrim($path, '/');
    }

    private function lifecycleResult(CommandResult $result, string $operation, string $success, string $name): CommandResult
    {
        return $result->withData(['operation' => $operation, 'summary' => $result->succeeded() ? $success : "Container $operation failed", 'container' => $this->detailsAfter($name)]);
    }

    private function detailsAfter(string $name): ?array
    {
        $inspection = $this->inspect($name);
        return $inspection->succeeded() ? ($inspection->data['container'] ?? null) : null;
    }

    private function details(array $container): array
    {
        $id = $container['Id'] ?? null;
        $ports = [];
        foreach (($container['NetworkSettings']['Ports'] ?? []) as $containerPort => $bindings) {
            foreach ($bindings ?? [] as $binding) {
                $ports[] = [
                    'container' => $containerPort,
                    'host_ip' => $binding['HostIp'] ?? null,
                    'host_port' => $binding['HostPort'] ?? null,
                ];
            }
        }
        $networks = [];
        foreach (($container['NetworkSettings']['Networks'] ?? []) as $networkName => $network) {
            $networks[$networkName] = [
                'ipv4' => $network['IPAddress'] ?? null,
                'ipv6' => $network['GlobalIPv6Address'] ?? null,
                'gateway' => $network['Gateway'] ?? null,
            ];
        }
        return [
            'id' => $id,
            'short_id' => is_string($id) ? substr($id, 0, 12) : null,
            'name' => isset($container['Name']) ? ltrim((string) $container['Name'], '/') : null,
            'image' => $container['Config']['Image'] ?? null,
            'hostname' => $container['Config']['Hostname'] ?? null,
            'status' => $container['State']['Status'] ?? null,
            'open_stdin' => $container['Config']['OpenStdin'] ?? null,
            'tty' => $container['Config']['Tty'] ?? null,
            'ports' => $ports,
            'networks' => $networks,
            'api' => $container,
        ];
    }

    private function requestedDefinition(ContainerDefinition $container): array
    {
        return [
            'name' => $container->name,
            'image' => $container->image,
            'hostname' => $container->hostname ?? $container->name,
            'labels' => $container->labels,
            'memory' => $container->memory,
            'command' => $container->command,
            'open_stdin' => $container->openStdin,
            'tty' => $container->tty,
            'port_forwards' => array_map(static fn ($forward) => [
                'host_ip' => $forward->hostIp,
                'host_port' => $forward->hostPort,
                'container_port' => $forward->containerPort,
                'protocol' => $forward->protocol(),
            ], $container->portForwards),
            'network' => $container->network === null ? null : [
                'name' => $container->network->name,
                'ipv4' => $container->network->ipv4,
                'ipv6' => $container->network->ipv6,
            ],
            'publish_exposed_ports' => $container->publishExposedPorts,
            'pasta_auto_publish' => $container->pastaAutoPublish,
        ];
    }

    private function requestedComposeInstance(ComposeInstanceDefinition $instance): array
    {
        return [
            'compose_file' => $instance->file,
            'compose_service' => $instance->service,
            'name' => $instance->name,
        ];
    }

    private function memoryBytes(string $memory): int
    {
        if (!preg_match('/^(\d+)([kmg]?)b?$/i', trim($memory), $parts)) {
            throw new \InvalidArgumentException("Invalid container memory value: $memory");
        }
        $power = match (strtolower($parts[2])) { 'k' => 1, 'm' => 2, 'g' => 3, default => 0 };
        return (int) $parts[1] * (1024 ** $power);
    }

    private function labels(array $labels): array
    {
        $normalized = [];
        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                [$key, $value] = array_pad(explode('=', (string) $value, 2), 2, '');
            }
            $normalized[(string) $key] = (string) $value;
        }
        return $normalized;
    }

    /** Decode Docker's multiplexed stdout/stderr stream. */
    private function demultiplex(string $stream): array
    {
        $stdout = $stderr = '';
        $offset = 0;
        $length = strlen($stream);
        while ($offset + 8 <= $length) {
            $header = unpack('Cstream/C3reserved/Nlength', substr($stream, $offset, 8));
            $offset += 8;
            $chunkLength = $header['length'];
            $chunk = substr($stream, $offset, $chunkLength);
            $offset += $chunkLength;
            if ($header['stream'] === 2) {
                $stderr .= $chunk;
            } else {
                $stdout .= $chunk;
            }
        }
        if ($offset === 0) {
            return [$stream, ''];
        }
        return [$stdout, $stderr];
    }
}
