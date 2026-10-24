<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

use InvalidArgumentException;

final readonly class ContainerDefinition
{
    public function __construct(
        public string $name,
        public string $image,
        public array $labels = [],
        public ?string $memory = null,
        public array $command = [],
    ) {
        if ($name === '' || $image === '') {
            throw new InvalidArgumentException('A container needs a name and an image.');
        }
    }
}
