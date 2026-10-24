#!/usr/bin/env bash
set -euo pipefail

cli=$1
name=$2
image=$3
remote=${4:-}
cli_args=()
if [[ -n "$remote" ]]; then
	cli_args=(--remote "$remote")
fi

cleanup() {
	"$cli" "${cli_args[@]}" remove --force "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$cli" "${cli_args[@]}" image-pull "$image"
"$cli" "${cli_args[@]}" create --name "$name" --image "$image" --label 'test.caller=bash' --start -- sh -c 'while :; do sleep 3600; done'
"$cli" "${cli_args[@]}" ready "$name" -- sh -c 'test -r /etc/os-release'
"$cli" "${cli_args[@]}" running "$name"
