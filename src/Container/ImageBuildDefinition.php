<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container;

final readonly class ImageBuildDefinition
{
    public function __construct(
        public string $file,
        public string $tag,
        public string $context,
        public ?string $from = null,
        public bool $noCache = false,
    ) {}
}
