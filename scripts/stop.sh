#!/usr/bin/env bash
source "$(dirname "$0")/_compose.sh"
rm -Rf ./tmp/* &&
$COMPOSE_CMD stop &
