<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

use InvalidArgumentException;

/** One host-to-container TCP or UDP port mapping. */
final readonly class PortForward
{
    /**
     * @param int|null $hostPort Requested host port; null asks the engine to choose one.
     * @param int $containerPort Port inside the container.
     * @param string $protocol tcp or udp (case-insensitive).
     * @param string|null $hostIp Optional host address to bind.
     *
     * @throws InvalidArgumentException When a port, protocol, or host address is invalid.
     */
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

    /** Return the normalized lowercase protocol. */
    public function protocol(): string
    {
        return strtolower($this->protocol);
    }

    /** Parse exactly one mapping in the CLI mapping syntax. */
    public static function parse(string $value): self
    {
        $forwards = self::parseList($value);
        if (count($forwards) !== 1) {
            throw new InvalidArgumentException('Expected one port mapping.');
        }
        return $forwards[0];
    }

    /**
     * Parse comma-separated ports and ranges into individual mappings.
     *
     * @return list<self>
     * @throws InvalidArgumentException When the mapping syntax or ranges are invalid.
     */
    public static function parseList(string $value): array
    {
        $forwards = [];
        foreach (explode(',', $value) as $mapping) {
            $mapping = trim($mapping);
            if ($mapping === '') {
                throw new InvalidArgumentException('Port mapping lists cannot contain empty entries.');
            }
            $protocol = 'tcp';
            if (str_contains($mapping, '/')) {
                [$mapping, $protocol] = array_pad(explode('/', $mapping, 2), 2, '');
            }
            if (!in_array(strtolower($protocol), ['tcp', 'udp'], true)) {
                throw new InvalidArgumentException('Port protocol must be tcp or udp.');
            }

            $hostIp = null;
            if (str_starts_with($mapping, '[')) {
                if (!preg_match('/^\[([^]]+)]:(.*)$/', $mapping, $ipParts) || filter_var($ipParts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    throw new InvalidArgumentException("Invalid port mapping '$mapping'; bracket IPv6 host addresses, for example [::1]:8080:80.");
                }
                $hostIp = $ipParts[1];
                $parts = explode(':', $ipParts[2]);
                if (count($parts) !== 2) {
                    throw new InvalidArgumentException("Invalid port mapping '$mapping'; use [HOST_IP:]HOST_PORT:CONTAINER_PORT[/tcp|udp].");
                }
                [$hostSpec, $containerSpec] = $parts;
                $hostPorts = $hostSpec === '' ? null : self::portRange($hostSpec);
                $containerPorts = self::portRange($containerSpec);
            } else {
                $parts = explode(':', $mapping);
            }
            if ($hostIp !== null) {
                // The bracketed IPv6 form has already been parsed above.
            } elseif (count($parts) === 1) {
                $hostPorts = null;
                $containerPorts = self::portRange($parts[0]);
                if (str_contains($parts[0], '-')) {
                    $hostPorts = $containerPorts;
                }
            } elseif (count($parts) === 2) {
                [$hostSpec, $containerSpec] = $parts;
                $hostPorts = $hostSpec === '' ? null : self::portRange($hostSpec);
                $containerPorts = self::portRange($containerSpec);
            } elseif (count($parts) === 3) {
                [$hostIp, $hostSpec, $containerSpec] = $parts;
                if ($hostIp === '') {
                    $hostIp = null;
                } elseif (filter_var($hostIp, FILTER_VALIDATE_IP) === false) {
                    throw new InvalidArgumentException("Invalid host IP address: $hostIp");
                }
                $hostPorts = $hostSpec === '' ? null : self::portRange($hostSpec);
                $containerPorts = self::portRange($containerSpec);
            } else {
                throw new InvalidArgumentException("Invalid port mapping '$mapping'; use [HOST_IP:]HOST_PORT:CONTAINER_PORT[/tcp|udp].");
            }

            if ($hostPorts !== null && count($hostPorts) !== count($containerPorts)) {
                throw new InvalidArgumentException("Port ranges in '$mapping' must contain the same number of ports.");
            }
            foreach ($containerPorts as $index => $containerPort) {
                $hostPort = $hostPorts === null ? null : $hostPorts[$index];
                $forwards[] = new self($hostPort, $containerPort, strtolower($protocol), $hostIp);
            }
        }
        return $forwards;
    }

    /** @return list<int> */
    private static function portRange(string $value): array
    {
        if (!preg_match('/^(\d+)(?:-(\d+))?$/', $value, $parts)) {
            throw new InvalidArgumentException("Invalid port or port range: $value");
        }
        $start = (int) $parts[1];
        $end = isset($parts[2]) && $parts[2] !== '' ? (int) $parts[2] : $start;
        if ($start < 1 || $end > 65535 || $end < $start) {
            throw new InvalidArgumentException("Invalid port range: $value");
        }
        return range($start, $end);
    }
}
