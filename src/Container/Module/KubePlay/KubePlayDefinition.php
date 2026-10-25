<?php

declare(strict_types=1);

namespace Magicoli\ContainerTools\Container\Module\KubePlay;

use InvalidArgumentException;

/** A Kubernetes YAML manifest handled by Podman's kube play command. */
final readonly class KubePlayDefinition
{
    /**
     * @param string $file Kubernetes YAML path on the client host.
     * @param list<string> $networks Podman networks to attach to the created pod.
     * @param bool $replace Replace existing resources when playing the manifest.
     */
    public function __construct(
        public string $file,
        public array $networks = [],
        public bool $replace = false,
    ) {
        if ($file === '') {
            throw new InvalidArgumentException('A Kubernetes manifest file cannot be empty.');
        }
        foreach ($networks as $network) {
            if (!is_string($network) || $network === '') {
                throw new InvalidArgumentException('Podman network names cannot be empty.');
            }
        }
    }
}
