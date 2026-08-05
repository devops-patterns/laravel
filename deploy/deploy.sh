#!/usr/bin/env bash
# Ручной деплой стека laravel в Docker Swarm (запускать НА СЕРВЕРЕ, из этой папки).
#
# Использование:
#   1) docker login ghcr.io                       # если образ приватный
#   2) создай .env с секретами и per-env (APP_KEY, DB_PASSWORD, REDIS_PASSWORD,
#      APP_ENV, APP_DEBUG, APP_URL, LOG_LEVEL, DB_DATABASE, DB_USERNAME) — см. README
#   3) ./deploy.sh <image-tag>                     # напр. ./deploy.sh sha-<...> или latest
#
# Скрипт склеивает .env.base + .env в финальный конфиг и сам выводит из него всё,
# что нужно compose (APP_DOMAIN, DB_*, версию конфига).
set -euo pipefail
cd "$(dirname "$0")"

IMAGE_TAG="${1:?usage: ./deploy.sh <image-tag>   (напр. sha-<...> или latest)}"
OVERLAY="${ENV_FILE:-.env}"
[ -f .env.base ] || { echo "ERROR: нет .env.base рядом со скриптом" >&2; exit 1; }
[ -f "$OVERLAY" ] || { echo "ERROR: нет $OVERLAY — создай его с секретами и per-env (см. README)" >&2; exit 1; }

# Финальный .env = статичная база + твой overlay. Это ровно тот файл, что
# монтируется в /app/.env.
umask 077
ASSEMBLED=.env.assembled
trap 'rm -f "$ASSEMBLED"' EXIT
cat .env.base "$OVERLAY" > "$ASSEMBLED"

# Версия конфига = хэш содержимого: тот же конфиг → тот же секрет (идемпотентно),
# изменил → новое имя секрета → Swarm катит новый конфиг.
ENV_VERSION="$(sha256sum "$ASSEMBLED" | cut -c1-12)"

val() { grep -E "^$1=" "$ASSEMBLED" | head -n1 | cut -d= -f2-; }
DB_DATABASE="$(val DB_DATABASE)"
DB_USERNAME="$(val DB_USERNAME)"
DB_PASSWORD="$(val DB_PASSWORD)"
REDIS_PASSWORD="$(val REDIS_PASSWORD)"
APP_URL="$(val APP_URL)"
APP_DOMAIN="${APP_URL#*://}"; APP_DOMAIN="${APP_DOMAIN%%/*}"

: "${DB_DATABASE:?DB_DATABASE пуст в .env}"
: "${DB_USERNAME:?DB_USERNAME пуст в .env}"
: "${APP_DOMAIN:?APP_URL пуст в .env}"

# Секрет-.env версионируется; пароли БД/redis — один раз (иммутабельны, postgres
# читает пароль только при initdb; смена — ALTER USER, а не пересоздание секрета).
docker secret inspect "laravel_env_${ENV_VERSION}" >/dev/null 2>&1 || \
  docker secret create "laravel_env_${ENV_VERSION}" "$ASSEMBLED"
docker secret inspect laravel_db_password >/dev/null 2>&1 || \
  printf '%s' "$DB_PASSWORD" | docker secret create laravel_db_password -
docker secret inspect laravel_redis_password >/dev/null 2>&1 || \
  printf '%s' "$REDIS_PASSWORD" | docker secret create laravel_redis_password -

export IMAGE_TAG ENV_VERSION APP_DOMAIN DB_DATABASE DB_USERNAME
docker stack deploy -c docker-compose.yml --with-registry-auth laravel

# Уборка старых env-секретов (используемый сейчас не удалится → || true).
docker secret ls -q -f name=laravel_env_ | while read -r s; do
  docker secret rm "$s" >/dev/null 2>&1 || true
done

echo
echo "✓ laravel задеплоен (env ${ENV_VERSION}, image ${IMAGE_TAG})"
echo "  Миграции (первый деплой): docker exec \$(docker ps -qf name=laravel_app | head -1) php artisan migrate --force"
