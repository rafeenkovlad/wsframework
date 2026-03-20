#!/usr/bin/env bash
rm -Rf ./tmp/* &&
docker compose -f ./docker-compose-wsframework.yml stop &
