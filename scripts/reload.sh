#!/usr/bin/env bash
docker compose -f ./docker-compose-wsframework.yml down &&
rm -Rf ./tmp/* &&
docker compose -f ./docker-compose-wsframework.yml up &
