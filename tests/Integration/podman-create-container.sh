#!/usr/bin/env bash
set -euo pipefail

cli=$1
name=$2
image=$3
connection=${4:-}
if [[ -n "$connection" ]]; then
	export CONTAINER_CONNECTION=$connection
fi

cleanup() {
	"$cli" destroy "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$cli" image-pull "$image"
"$cli" create --name "$name" --image "$image" --label 'test.caller=bash'
"$cli" wait-ready "$name" -- wget -q -O /dev/null http://127.0.0.1/
"$cli" status "$name"
