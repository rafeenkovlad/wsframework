#!/usr/bin/env bash
source "$(dirname "$0")/_compose.sh"
$COMPOSE_CMD down &&
rm -Rf ./tmp/* &&
$COMPOSE_CMD up &
