#!/usr/bin/env bash
# Switch sibling Composer dependencies with build-tools.

tool="$(dirname "$0")/../vendor/bin/build-tools"
[[ -x "$tool" ]] || { echo "build-tools is not installed: composer install" >&2; exit 1; }
exec "$tool" switch "$@"
