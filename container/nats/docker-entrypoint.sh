#!/bin/sh
set -e
if [ "$#" -eq 0 ] || [ "${1#-}" != "$1" ]; then
    set -- nats-server --jetstream "$@"
fi
exec "$@"
