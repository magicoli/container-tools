<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

use InvalidArgumentException;

final readonly class PortForward
{
    public function __construct(
        public ?int $hostPort,
        public int $containerPort,
        public string $protocol = 'tcp',
        public ?string $hostIp = null,
    ) {
        if (($hostPort !== null && ($hostPort < 1 || $hostPort > 65535)) || $containerPort < 1 || $containerPort > 65535) {
            throw new InvalidArgumentException('Ports must be between 1 and 65535; omit the host port for an automatically assigned port.');
        }
        if (!in_array(strtolower($protocol), ['tcp', 'udp'], true)) {
            throw new InvalidArgumentException('Port protocol must be tcp or udp.');
        }
        if ($hostIp !== null && filter_var($hostIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("Invalid host IP address: $hostIp");
        }
    }

    public function protocol(): string
    {
        return strtolower($this->protocol);
    }

    public static function parse(string $value): self
    {
        if (!preg_match('/^(?:(\d+):)?(\d+)(?:\/(tcp|udp))?$/i', $value, $parts)) {
            throw new InvalidArgumentException("Invalid port mapping '$value'; use [HOST_PORT:]CONTAINER_PORT[/tcp|udp].");
        }

        return new self(
            ($parts[1] ?? '') === '' ? null : (int) $parts[1],
            (int) $parts[2],
            strtolower($parts[3] ?? 'tcp'),
        );
    }
}
