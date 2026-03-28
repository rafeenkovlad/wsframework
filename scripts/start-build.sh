#!/usr/bin/env bash
source "$(dirname "$0")/_compose.sh"
cp "./environment/.env.${APP_ENV}" "./.env"
ensure_vendor
$COMPOSE_CMD up --build &
