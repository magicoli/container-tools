#!/usr/bin/env bash
set -euo pipefail

cli=$1
name=$2
image=$3

cleanup() {
	php "$cli" remove --force "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

php "$cli" image-pull "$image"
php "$cli" create --name "$name" --image "$image" --label 'test.caller=bash' -- sh -c 'while :; do sleep 3600; done'
php "$cli" start "$name"
php "$cli" ready "$name" -- sh -c 'test -r /etc/os-release'
php "$cli" running "$name"
