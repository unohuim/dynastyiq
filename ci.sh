#!/usr/bin/env bash
set -euo pipefail

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
#bash scripts/ci/blade-guardrails.sh
#bash scripts/ci/js-syntax-guardrails.sh

# --- Frontend build ---
if [ -f package-lock.json ]; then
  npm ci
else
  npm install
fi
npm run build

# --- DB migrate only if CI env declares a DB connection ---
if grep -qE '^\s*DB_CONNECTION=' .env.ci; then
  php artisan migrate --env=ci --force
fi

# --- Run tests ---
php artisan test --env=ci