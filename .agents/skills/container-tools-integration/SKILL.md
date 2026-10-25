---
name: container-tools-integration
description: Integrate the magicoli/container-tools PHP library or command into another application. Use when adding container lifecycle, Podman remote, Compose, or Kube Play support through this package.
---

# Integrate container-tools

Read `DEVELOPERS.md` and [`docs/php-api.md`](../../../docs/php-api.md) before changing an integration. They define the package boundary, PHP contracts, shell command, connection configuration, module semantics, and current limits.

Key decisions:

- `container-tools` is independent of application/domain packages. A host-side installer or CLI can call its `vendor/bin/container-tools` command or PHP API and target local or remote Podman. Do not require the consumer's application runtime to be installed on the target just to manage it.
- The command adapter is a supported host-side integration path for installers and terminal-facing CLIs. It works from interactive or non-interactive host terminals; its current `exec` operation does not attach an interactive PTY inside the container.
- Use `ContainerManagerInterface` and structured `CommandResult` data in PHP. Keep domain policy in the consumer and Podman mechanics here. Do not parse human output or duplicate the REST client.
- Respect `CONTAINER_CONNECTION`, `CONTAINER_HOST`, and `DOCKER_HOST`; caller-provided environment/configuration wins. Never hardcode a personal remote or force local Podman in tests.
- `create()` through the PHP API does not start the container; call `start()`. The CLI's default create workflow starts it unless `--no-start` is set.
- Compose and Kube Play are distinct modules with their own resource semantics. Check `DEVELOPERS.md` before extending them or adding another format.

When making changes, read the existing implementation and focused tests first. Add separate, concise tests for behaviors that need separate output, respect the configured remote, run focused tests, then the full suite when the host permits it.
