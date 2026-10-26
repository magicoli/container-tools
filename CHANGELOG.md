## Changelog

### Unreleased

- magicoli/build-tools 0.1.10 => 0.1.11

### 0.1.0-alpha.1

- feat: copy local files and directories into containers through the PHP API and CLI
- fix: resolve the Composer autoloader when the CLI is invoked through a symlink
- fix: wait for remote Podman SSH tunnels before sending API requests
- fix: report removed containers as successful when Podman only warns during network cleanup

### 0.1.0-dev.2

- feat: add Bash completion for CLI

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
