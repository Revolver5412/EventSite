#!/usr/bin/env sh
set -e

# Run confd
/usr/local/bin/confd -onetime -backend env

exec "$@"
