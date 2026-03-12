#!/usr/bin/env bash
set -euo pipefail

COMPOSE_FILE="docker-compose.dev-server.yml"
ENV_FILE=".env.docker"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE. Create it from .env.docker.example first."
  exit 1
fi

DC="docker compose -f ${COMPOSE_FILE} --env-file ${ENV_FILE}"

echo "Starting base containers..."
$DC up -d --build mysql redis app web

echo "Installing PHP dependencies..."
$DC run --rm app composer install --no-interaction --prefer-dist

echo "Installing Node dependencies and building assets..."
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" --profile tools run --rm node

if grep -q '^APP_KEY=$' "$ENV_FILE"; then
  echo "Generating APP_KEY..."
  $DC run --rm app php artisan key:generate --force
fi

echo "Running database migrations..."
$DC run --rm app php artisan migrate --force

echo "Ensuring storage link exists..."
$DC run --rm app php artisan storage:link || true

echo "Optimizing Laravel cache..."
$DC run --rm app php artisan optimize

echo "Starting queue worker and scheduler..."
$DC up -d queue scheduler

echo "Deployment complete."
APP_URL="$(grep '^APP_URL=' "$ENV_FILE" | cut -d '=' -f2- || true)"
if [[ -n "$APP_URL" ]]; then
  echo "Open: ${APP_URL}"
fi
