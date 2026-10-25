<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

use InvalidArgumentException;

/** Immutable configuration for creating a Podman network. */
final readonly class NetworkDefinition
{
    /**
     * @param string $driver bridge, macvlan, or ipvlan.
     * @param string|null $parent Parent interface for macvlan or ipvlan.
     * @param string $ipamDriver host-local, dhcp, or none.
     * @param string|null $ipv4Subnet IPv4 subnet in CIDR notation.
     * @param string|null $ipv4Gateway IPv4 gateway; requires ipv4Subnet.
     * @param string|null $ipv6Subnet IPv6 subnet in CIDR notation; requires ipv6Enabled.
     * @param string|null $ipv6Gateway IPv6 gateway; requires ipv6Subnet.
     */
    public function __construct(
        public string $name,
        public string $driver = 'bridge',
        public ?string $parent = null,
        public string $ipamDriver = 'host-local',
        public ?string $ipv4Subnet = null,
        public ?string $ipv4Gateway = null,
        public ?string $ipv6Subnet = null,
        public ?string $ipv6Gateway = null,
        public bool $ipv6Enabled = false,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A network name cannot be empty.');
        }
        if (!in_array($driver, ['bridge', 'macvlan', 'ipvlan'], true)) {
            throw new InvalidArgumentException('Network driver must be bridge, macvlan, or ipvlan.');
        }
        if ($driver === 'bridge' && $parent !== null) {
            throw new InvalidArgumentException('A parent interface is only valid for macvlan or ipvlan networks.');
        }
        if (!in_array($ipamDriver, ['host-local', 'dhcp', 'none'], true)) {
            throw new InvalidArgumentException('IPAM driver must be host-local, dhcp, or none.');
        }
        if ($ipamDriver === 'dhcp' && ($ipv4Subnet !== null || $ipv6Subnet !== null)) {
            throw new InvalidArgumentException('DHCP networks cannot declare static subnets.');
        }
        if (($ipv4Gateway !== null && $ipv4Subnet === null) || ($ipv6Gateway !== null && $ipv6Subnet === null)) {
            throw new InvalidArgumentException('A network gateway requires its matching subnet.');
        }
        foreach ([[$ipv4Subnet, FILTER_FLAG_IPV4, 'IPv4 subnet'], [$ipv6Subnet, FILTER_FLAG_IPV6, 'IPv6 subnet']] as [$subnet, $family, $label]) {
            if ($subnet === null) {
                continue;
            }
            [$address, $prefix] = array_pad(explode('/', $subnet, 2), 2, null);
            $maximum = $family === FILTER_FLAG_IPV4 ? 32 : 128;
            if (filter_var($address, FILTER_VALIDATE_IP, $family) === false || $prefix === null || !ctype_digit($prefix) || (int) $prefix > $maximum) {
                throw new InvalidArgumentException("Invalid $label: $subnet");
            }
        }
        if ($ipv4Gateway !== null && filter_var($ipv4Gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException("Invalid IPv4 gateway: $ipv4Gateway");
        }
        if ($ipv6Gateway !== null && filter_var($ipv6Gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException("Invalid IPv6 gateway: $ipv6Gateway");
        }
        if ($ipv6Subnet !== null && !$ipv6Enabled) {
            throw new InvalidArgumentException('An IPv6 subnet requires IPv6 to be enabled for the network.');
        }
    }
}
