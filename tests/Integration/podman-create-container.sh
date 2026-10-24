#!/usr/bin/env bash
set -euo pipefail

cli=$1
name=$2
image=$3
containerfile=$4
context=$5
connection=${6:-}
if [[ -n "$connection" ]]; then
	export CONTAINER_CONNECTION=$connection
fi

run_cli() {
	if [[ -n "$connection" ]]; then
		"$cli" "--container-connection=$connection" "$@"
	else
		"$cli" "$@"
	fi
}

cleanup() {
	run_cli destroy "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

build_output=$(run_cli build --file="$containerfile" --tag="$image" "$context")
grep -q 'Image: ' <<<"$build_output"
create_output=$(run_cli create --name="$name" --image="$image" --label='test.caller=bash')
printf '%s\n' "$create_output"
if grep -q 'API inspection:' <<<"$create_output"; then
	echo 'Full API inspection leaked into the default output' >&2
	exit 1
fi
verbose_output=$(run_cli status "$name" -v)
printf '%s\n' "$verbose_output"
grep -q 'API inspection:' <<<"$verbose_output"
grep -q '"GraphDriver"' <<<"$verbose_output"
quiet_output=$(run_cli -q status "$name")
[[ -z "$quiet_output" ]]
help_output=$(run_cli -h)
grep -q 'usage: container-tools' <<<"$help_output"
dry_run_output=$(run_cli -d create --name="$name-dry-run" --image="$image")
grep -q 'Dry run: no action performed' <<<"$dry_run_output"
if run_cli status "$name-dry-run" >/dev/null 2>&1; then
	echo 'Dry run unexpectedly created a container' >&2
	exit 1
fi
run_cli wait-ready "$name" -- sh -c 'wget -q -O /dev/null http://127.0.0.1/ && cat /proc/net/tcp /proc/net/tcp6 | grep -q ":0016 " && cat /proc/net/tcp /proc/net/tcp6 | grep -q ":0050 " && cat /proc/net/tcp /proc/net/tcp6 | grep -q ":01BB "'
run_cli status "$name"
