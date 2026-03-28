#!/usr/bin/env bash
source "$(dirname "$0")/_compose.sh"
ensure_vendor
$COMPOSE_CMD up &
