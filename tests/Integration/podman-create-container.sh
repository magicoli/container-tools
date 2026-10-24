#!/usr/bin/env bash
set -euo pipefail

cli=$1
name=$2
image=$3
connection=${4:-}
cli_args=()
if [[ -n "$connection" ]]; then
	cli_args=(--connection "$connection")
fi

cleanup() {
	"$cli" "${cli_args[@]}" destroy "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$cli" "${cli_args[@]}" image-pull "$image"
if [[ "$image" == 'docker.io/library/alpine:3.24' && -z "${CONTAINER_IMAGE:-}" ]]; then
	"$cli" "${cli_args[@]}" create --name "$name" --label 'test.caller=bash'
else
	"$cli" "${cli_args[@]}" create --name "$name" --image "$image" --label 'test.caller=bash' -- sh -c 'while :; do sleep 3600; done'
fi
"$cli" "${cli_args[@]}" wait-ready "$name" -- sh -c 'test -r /etc/os-release'
"$cli" "${cli_args[@]}" status "$name"
