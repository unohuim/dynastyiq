#!/usr/bin/env bash
set -euo pipefail

LOG_FILE="ci.output"
: > "$LOG_FILE"
exec > >(tee -a "$LOG_FILE") 2>&1

require_cmd() {
  local cmd="$1"

  if ! command -v "${cmd}" >/dev/null 2>&1; then
    echo "ERROR: Required command not found on PATH: ${cmd}"
    exit 1
  fi
}

# --- Tooling prerequisites ---
require_cmd php
require_cmd composer
require_cmd node
require_cmd npm
require_cmd rg

# --- Ensure we have a CI env file ---
if [ ! -f .env.ci ]; then
  cp .env.example .env.ci
fi

# --- Install PHP dependencies ---
composer install --no-interaction --prefer-dist --optimize-autoloader

# --- App key (CI) ---
php artisan key:generate --env=ci --force

# --- Permissions (safe no-op if not needed) ---
chmod -R 777 storage bootstrap/cache 2>/dev/null || true

# --- Guardrails (fail fast) ---
# bash scripts/ci/blade-guardrails.sh
# bash scripts/ci/js-syntax-guardrails.sh

# --- Frontend build ---
if [ -f package-lock.json ]; then
  npm ci
else
  npm install
fi

npm run build

# --- DB migrate only if CI env declares a DB connection ---
if grep -qE '^\s*DB_CONNECTION=pgsql\s*$' .env.ci; then
  if ! grep -qE '^\s*DB_HOST=' .env.ci && [ -z "${DB_HOST:-}" ]; then
    export DB_HOST="127.0.0.1"
  fi

  if ! grep -qE '^\s*DB_PORT=' .env.ci && [ -z "${DB_PORT:-}" ]; then
    export DB_PORT="5432"
  fi

  if ! grep -qE '^\s*DB_DATABASE=' .env.ci && [ -z "${DB_DATABASE:-}" ]; then
    export DB_DATABASE="testing"
  fi

  if ! grep -qE '^\s*DB_USERNAME=' .env.ci && [ -z "${DB_USERNAME:-}" ]; then
    export DB_USERNAME="$(id -un)"
  fi

  if ! grep -qE '^\s*DB_PASSWORD=' .env.ci && [ -z "${DB_PASSWORD:-}" ]; then
    export DB_PASSWORD=""
  fi

  php artisan migrate --env=ci --force
elif grep -qE '^\s*DB_CONNECTION=' .env.ci; then
  php artisan migrate --env=ci --force
fi

# --- Run tests ---
php artisan test
