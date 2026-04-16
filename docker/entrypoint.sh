#!/usr/bin/env bash
set -euo pipefail

for secret in app_key db_password redis_password; do
  file="/run/secrets/$secret"
  if [ -f "$file" ]; then
    export "$(echo "$secret" | tr '[:lower:]' '[:upper:]')=$(cat "$file")"
  fi
done

php artisan storage:link --force
php artisan optimize

exec "$@"
