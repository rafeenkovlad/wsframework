#!/usr/bin/env bash
source .env.setup
cp "./environment/.env.${APP_ENV}" "./.env"
docker compose -f ./docker-compose-wsframework.yml up --build &
