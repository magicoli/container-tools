<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

use InvalidArgumentException;

/** Selects one service from a standard Compose file as a container instance. */
final readonly class ComposeInstanceDefinition
{
    public function __construct(
        public string $file,
        public string $service,
        public ?string $name = null,
    ) {
        if ($file === '') {
            throw new InvalidArgumentException('A Compose file cannot be empty.');
        }
        if ($service === '') {
            throw new InvalidArgumentException('A Compose service cannot be empty.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('A container name cannot be empty.');
        }
    }
}
