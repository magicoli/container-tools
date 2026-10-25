<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

/**
 * Outcome of one library operation.
 *
 * A zero status means success. On failure, stderr contains the available
 * diagnostic; data contains operation-specific context when available.
 * The data shape is documented per operation in docs/php-api.md.
 */
final readonly class CommandResult
{
    /**
     * @param int $status Operation or command exit status; zero means success.
     * @param array<string, mixed> $data Structured operation-specific result data.
     */
    public function __construct(
        public int $status,
        public string $stdout = '',
        public string $stderr = '',
        public array $data = [],
    ) {}

    /** Whether status is zero. */
    public function succeeded(): bool
    {
        return $this->status === 0;
    }

    /** Return a copy with DATA merged over existing data; matching keys are replaced. */
    public function withData(array $data): self
    {
        return new self($this->status, $this->stdout, $this->stderr, [...$this->data, ...$data]);
    }
}
