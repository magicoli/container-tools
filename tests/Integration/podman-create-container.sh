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

create_output=$("$cli" create --name "$name" --image "$image" --label 'test.caller=bash')
printf '%s\n' "$create_output"
if grep -q 'API inspection:' <<<"$create_output"; then
	echo 'Full API inspection leaked into the default output' >&2
	exit 1
fi
verbose_output=$("$cli" --verbose status "$name")
printf '%s\n' "$verbose_output"
grep -q 'API inspection:' <<<"$verbose_output"
grep -q '"GraphDriver"' <<<"$verbose_output"
"$cli" wait-ready "$name" -- wget -q -O /dev/null http://127.0.0.1/
"$cli" status "$name"
