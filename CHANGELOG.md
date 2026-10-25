## Changelog

### Unreleased

- fix: preserve command-first completion order with modern Bash

### 0.1.0-dev.1

- feat: provide PHP and CLI interfaces for Podman image, network, and container lifecycle management
- feat: support local and remote Podman connections through its REST API
- feat: build Containerfile images with cache-aware results
- feat: check container readiness and execute commands
- feat: add port publishing, pasta forwarding, and direct network addressing
- feat: manage Compose services and Kube Play deployments
- feat: return structured lifecycle and build results
- feat: add Debian packaging and standard build and release tools
- fix: require the PHP cURL package matching the system PHP version
- feat: add Bash completion for the command-line interface
- fix: keep completion options concise and hide options already used
