<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

use InvalidArgumentException;

final readonly class ContainerDefinition
{
    public const DEFAULT_IMAGE = 'docker.io/library/alpine:3.24';

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
