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
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A container name cannot be empty.');
        }
        if ($image === '') {
            throw new InvalidArgumentException('A container image cannot be empty.');
        }
    }
}
