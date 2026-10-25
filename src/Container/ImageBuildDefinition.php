<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

/** Immutable input for building and tagging one image. */
final readonly class ImageBuildDefinition
{
    /**
     * @param string $file Containerfile path, inside CONTEXT.
     * @param string $tag Image reference to assign to the build.
     * @param string $context Local directory sent as the build context.
     * @param string|null $from Optional override for the first FROM image.
     * @param bool $noCache Disable reuse of cached build layers.
     */
    public function __construct(
        public string $file,
        public string $tag,
        public string $context,
        public ?string $from = null,
        public bool $noCache = false,
    ) {}
}
