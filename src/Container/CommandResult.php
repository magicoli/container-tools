<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

final readonly class CommandResult
{
    public function __construct(
        public int $status,
        public string $stdout = '',
        public string $stderr = '',
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 0;
    }
}
