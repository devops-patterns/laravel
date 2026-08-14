# deploy/ — артефакты деплоя в Docker Swarm (gitops)

Всё здесь — только для деплоя на сервер. Локальной разработки не касается.
Основной путь — **через GitHub CI** (см. `.github/workflows/`); `deploy.sh` оставлен
как ручной фолбэк на сервере.

## Файлы
- `docker-compose.yml` — Swarm-стек (`app/queue/scheduler/db/redis`), traefik-лейблы, сеть
  `traefik-ingress`. Именами соблюдает контракт gitops (стек `laravel`, сервис `db` →
  `laravel_db`, том `storage` → `laravel_storage`). CI доставляет его на сервер (scp) каждым
  деплоем — версионируется в git.
- `.env.base` — статичная часть конфига, одинаковая во всех окружениях (драйверы, `DB_HOST=db`,
  `REDIS_HOST=redis`, `MAIL_*` …). Секретов и per-env значений тут нет. CI доставляет его на
  сервер и дописывает поверх значения из GitHub vars/secrets.
- `deploy.sh` — ручной деплой на сервере (фолбэк): склеивает `.env.base + .env`, создаёт
  секреты, катит стек. В обычном потоке не нужен — деплоит CI.

## Модель конфига (слоёная)
Конфиг собирается из трёх слоёв:
- **git `.env.base`** — статика (одинаково везде).
- **GitHub vars** — per-env несекретное (`APP_ENV`, `APP_DEBUG`, `APP_URL`, `LOG_LEVEL`).
- **GitHub secrets** — секреты (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`).

CI на сервере собирает финальный `.env` = `.env.base` + значения из vars/secrets, кладёт его
ОДНИМ Docker-секретом `laravel_env_<hash>` (имя версионируется по sha256 содержимого) и
монтирует в `/app/.env` — единственный источник конфига приложения. Изменил любой слой → новый
хэш → `docker stack deploy` катит новый конфиг (`service update` смену секрета НЕ подхватывает,
поэтому CI всегда делает `stack deploy`). Пароли БД/redis идут ещё и отдельными секретами
`laravel_db_password` / `laravel_redis_password` (их читают postgres/redis; создаются один раз).

`APP_DOMAIN` для traefik-лейбла CI выводит из `APP_URL` — отдельной переменной не нужно.

## CI (основной путь)
`build.yml` при push в `master` / теге `v*` собирает образ `ghcr.io/<repo>:sha-<sha>` и
`:<version>`. Дальше:
- **staging** — `deploy-staging.yml` по завершении `build` на `master` (или вручную
  `workflow_dispatch`). Тег образа `sha-<sha>`.
- **production** — `deploy-production.yml` по завершении `build` на теге `v*` (или вручную
  с указанием тега). Перед раскатом — best-effort бэкап БД (`/opt/backups/bin/run-backup.sh`).

Каждый деплой: scp `docker-compose.yml` + `.env.base` → собрать `.env` из слоёв → создать
секреты → `artisan down` → `docker stack deploy` → дождаться нового образа → `migrate --force`
→ `/up` через Traefik → `artisan up` → убрать старые env-секреты.

### Что настроить в GitHub (Settings → Environments: `staging` и `production`)
**Secrets:**
- `APP_KEY` — `php artisan key:generate --show`
- `DB_PASSWORD`, `REDIS_PASSWORD`
- `DEPLOY_SSH_PRIVATE_KEY` — приватный ключ deploy-юзера (публичный уже на сервере с провижининга)

**Variables (vars):**
- `APP_ENV` (`production`), `APP_DEBUG` (`false`), `LOG_LEVEL` (`warning`)
- `APP_URL` — совпадает с `domains[env]` в `gitops/stacks/laravel/vars.yml`
  (staging: `https://laravel-staging.doma1n.ru`)
- `DEPLOY_USER` (`deploy`), `SSH_PORT` (напр. `52146`)

> `DB_DATABASE` / `DB_USERNAME` (`laravel`/`laravel`) — в `.env.base`, отдельными vars не нужны.
- staging: `STAGING_HOST` = IP/host стейджа; production: `PROD_HOST` = IP/host прода

> Пре-деплой бэкап на проде идёт через `sudo -n /opt/backups/bin/run-backup.sh laravel`.
> Чтобы он реально снимался, deploy-юзеру нужна sudoers-строка без пароля на этот скрипт;
> иначе шаг мягко пропускается (cron borgmatic и так снимает по расписанию).

## Предпосылки (делает gitops, уже развёрнуто)
- Сервер с Docker Swarm, сеть `traefik-ingress`, Traefik + TLS (Let's Encrypt).
- DNS-запись домена: в gitops `make update-dns ENV=staging` (→ `laravel-staging.doma1n.ru`).
- Образ в ghcr: `ghcr.io/<repo>:<tag>` (собирает `build.yml`).

## Порядок первого раската (staging)
1. В gitops: `make update-dns ENV=staging` — создаст `laravel-staging.doma1n.ru`.
2. В GitHub задать Environments (secrets/vars выше).
3. Push в `master` → `build.yml` соберёт образ → `deploy-staging.yml` раскатит стек.
4. Проверка: `https://laravel-staging.doma1n.ru/up` → 200; на сервере `docker service ls`
   (`laravel_app/queue/scheduler/db/redis` Running), `docker secret ls`
   (`laravel_env_<hash>`, `laravel_db_password`, `laravel_redis_password`),
   `docker volume inspect laravel_storage`.

Прод — то же самое через тег: `git tag v1.0.0 && git push --tags`.

## Ручной фолбэк (на сервере, без CI)
```bash
scp -r deploy deploy@<HOST>:/opt/stacks/laravel      # -P <SSH_PORT> при нестандартном порте
ssh deploy@<HOST> -p <SSH_PORT>
cd /opt/stacks/laravel/deploy
docker login ghcr.io                                 # если образ приватный

# .env — overlay поверх .env.base (только секреты + per-env):
cat > .env <<'EOF'
APP_KEY=            # php artisan key:generate --show
DB_PASSWORD=
REDIS_PASSWORD=
APP_ENV=production
APP_DEBUG=false
APP_URL=https://laravel-staging.doma1n.ru
LOG_LEVEL=warning
EOF
$EDITOR .env

./deploy.sh sha-<полный-sha>                          # склеит .env.base + .env, катит стек
```
