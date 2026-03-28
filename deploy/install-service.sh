#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# Проверяем APP_ENV из .env.setup
source "$PROJECT_DIR/.env.setup"

if [ "$APP_ENV" != "server" ]; then
  echo "ERROR: APP_ENV='$APP_ENV'. Установка сервиса разрешена только для APP_ENV='server'."
  echo "Проверьте .env.setup"
  exit 1
fi

cp "$SCRIPT_DIR/wsframework.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable wsframework.service
echo "wsframework.service installed and enabled."
