<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

use InvalidArgumentException;

/** Select one service from a Compose file for creation as a container instance. */
final readonly class ComposeInstanceDefinition
{
    /**
     * @param string $file Compose file path on the client host.
     * @param string $service Service to select from the file.
     * @param string|null $name Optional container name exposed to Compose interpolation.
     */
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
