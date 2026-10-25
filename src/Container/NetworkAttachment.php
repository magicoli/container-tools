<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

use InvalidArgumentException;

/** Select a network and optional fixed addresses for a container. */
final readonly class NetworkAttachment
{
    /**
     * @param string $name Existing network name or supported network mode.
     * @param string|null $ipv4 Optional fixed IPv4 address on that network.
     * @param string|null $ipv6 Optional fixed IPv6 address on that network.
     */
    public function __construct(
        public string $name,
        public ?string $ipv4 = null,
        public ?string $ipv6 = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A network name cannot be empty.');
        }
        if ($ipv4 !== null && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException("Invalid IPv4 address: $ipv4");
        }
        if ($ipv6 !== null && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException("Invalid IPv6 address: $ipv6");
        }
    }
}
