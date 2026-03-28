#!/usr/bin/env bash
source "$(dirname "$0")/_compose.sh"
$COMPOSE_CMD stop &&
rm -Rf ./tmp/* &&
$COMPOSE_CMD up &
