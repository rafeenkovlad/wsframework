#!/usr/bin/env bash
docker compose -f ./docker-compose-wsframework.yml stop &&
rm -Rf ./tmp/* &&
docker compose -f ./docker-compose-wsframework.yml up &
