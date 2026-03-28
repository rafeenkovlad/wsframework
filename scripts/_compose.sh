#!/usr/bin/env bash
source .env.setup

COMPOSE_CMD="docker compose -f ./docker-compose-wsframework.yml"
if [ "$APP_ENV" = "local" ]; then
  COMPOSE_CMD="$COMPOSE_CMD -f ./docker-compose-wsframework.local.yml"
fi

ensure_vendor() {
  if [ ! -f ./vendor/autoload.php ]; then
    echo "vendor/ not found, running composer install..."
    $COMPOSE_CMD run --rm wsframework composer install
  fi
}
