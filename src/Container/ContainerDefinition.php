<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

use InvalidArgumentException;

/** Immutable configuration for creating one container. */
final readonly class ContainerDefinition
{
    /** Default image used when the caller does not provide one. */
    public const DEFAULT_IMAGE = 'docker.io/library/alpine:3.24';

    /**
     * @param array<string|int, string> $labels Associative labels or KEY=VALUE strings.
     * @param string|null $memory Memory limit, such as 512m or 1g.
     * @param list<string> $command Replacement command and arguments; empty preserves the image command.
     * @param string|null $hostname Defaults to the supplied name when omitted.
     * @param bool $openStdin Keep stdin open; defaults to true.
     * @param bool $tty Allocate a TTY; defaults to true.
     * @param list<PortForward> $portForwards Explicit published port mappings.
     * @param NetworkAttachment|null $network Optional network or network mode and addresses.
     * @param bool $publishExposedPorts Publish image EXPOSE metadata on automatic host ports.
     * @param bool $pastaAutoPublish Enable pasta's automatic port forwarding.
     *
     * By default stdin remains open and a TTY is allocated, matching Podman
     * Desktop's create defaults. `create()` creates but does not start the container.
     */
    public function __construct(
        public ?string $name = null,
        public string $image = self::DEFAULT_IMAGE,
        public array $labels = [],
        public ?string $memory = null,
        public array $command = [],
        public ?string $hostname = null,
        public bool $openStdin = true,
        public bool $tty = true,
        public array $portForwards = [],
        public ?NetworkAttachment $network = null,
        public bool $publishExposedPorts = true,
        public bool $pastaAutoPublish = false,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A container name cannot be empty.');
        }
        if ($image === '') {
            throw new InvalidArgumentException('A container image cannot be empty.');
        }
        foreach ($portForwards as $forward) {
            if (!$forward instanceof PortForward) {
                throw new InvalidArgumentException('Port forwards must be PortForward instances.');
            }
        }
    }
}
