#!/usr/bin/env bash
set -euo pipefail

errors=0
view_excludes=(
  --glob '!resources/views/vendor/**'
  --glob '!resources/views/layouts/**'
  --glob '!resources/views/components/**'
)

if rg --pcre2 "<script(?![^>]*type=\"application/json\")" resources/views "${view_excludes[@]}"; then
  echo "ERROR: Non-JSON <script> tags found in Blade views."
  errors=1
fi

if rg "on(click|change|submit|input|keydown|keyup|keypress|blur|focus)\s*=\s*['\"]" resources/views "${view_excludes[@]}"; then
  echo "ERROR: Inline JS handlers found in Blade views."
  errors=1
fi

if [ "$errors" -ne 0 ]; then
  exit 1
fi