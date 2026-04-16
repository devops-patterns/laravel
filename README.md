# Laravel + gitops deployment

Проект деплоится на Docker Swarm кластер, поднятый репозиторием [devops-patterns/gitops](https://github.com/devops-patterns/gitops) (Ansible). Этот документ описывает только часть на стороне Laravel-проекта: сборку образа, CI/CD и поток деплоя. Инфраструктура (compose, Traefik, Postgres, Redis, бэкапы) описана в gitops-репозитории и сюда не входит.

## Архитектура

- **Registry**: `ghcr.io/devops-patterns/laravel` (приватный, GHCR)
- **Runtime**: FrankenPHP 1 + PHP 8.5 (Caddy внутри, один процесс)
- **Database**: PostgreSQL 18 (поднимается gitops-стеком, сервис `db`)
- **Cache/Session/Queue**: Redis (сервис `redis`)
- **Health endpoint**: `GET /up` (Laravel 13 из коробки, `bootstrap/app.php`)

Один и тот же образ запускается как `app`, `worker` и `scheduler` — последние два переопределяют CMD через gitops compose.

### Секреты

Чувствительные данные (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`) хранятся как **Docker Swarm secrets** — зашифрованы в raft-логе, смонтированы в контейнерах как файлы в `/run/secrets/`. Entrypoint образа читает их и экспортирует как env vars перед стартом Laravel.

Остальные переменные (`APP_NAME`, `APP_ENV`, `APP_URL` и т.п.) передаются как environment в compose через shell substitution при `docker stack deploy`.

## Два стенда

| Environment | Trigger              | Approval                    | Image tag             |
|-------------|----------------------|-----------------------------|-----------------------|
| staging     | push в `master`      | авто                        | `sha-<commit>`        |
| production  | push git-тега `v*`   | ручной (GitHub Environment) | `<semver>` + `latest` |

### Поток деплоя

**Первый деплой** (стека нет):
1. Логин в GHCR через `GITHUB_TOKEN`.
2. Создание Docker Swarm secrets из GitHub secrets (идемпотентно).
3. `docker stack deploy` — поднимает весь стек (app, worker, scheduler, db, redis).
4. Ожидание готовности app → миграции.

**Обновление** (стек уже существует):
1. Логин в GHCR.
2. Graceful stop worker/scheduler (`scale 0`).
3. `docker service update --image` для app, worker, scheduler (worker/scheduler при scale=0 не запускаются).
4. Ожидание готовности app → миграции + `cache:clear`.
5. `scale 1` для worker/scheduler — стартуют с новым образом после миграций.
6. (только prod) Pre-deploy бэкап перед шагом 2.

Stack deploy используется **только при первом деплое**. Последующие обновления через `service update --image` — это позволяет не давать воркерам стартовать до завершения миграций.

## Где какие переменные лежат

### Уровень организации (`devops-patterns`) — уже настроено

| Тип | Имя | Назначение |
|-----|-----|------------|
| secret | `DEPLOY_SSH_PRIVATE_KEY` | приватный ключ deploy-юзера |
| var    | `STAGING_HOST`           | IP/hostname staging-сервера |
| var    | `PROD_HOST`              | IP/hostname production-сервера |
| var    | `SSH_PORT`               | SSH-порт |
| var    | `DEPLOY_USER`            | имя deploy-юзера на серверах |

Для аутентификации в GHCR используется встроенный `GITHUB_TOKEN` — ничего дополнительно добавлять не нужно.

### Уровень репозитория → Environment `staging`

Settings → Environments → New environment → `staging`. Protection rules не нужны.

| Тип | Имя | Пример значения |
|-----|-----|-----------------|
| secret | `APP_KEY`        | `base64:...` (`echo "base64:$(openssl rand -base64 32)"`) |
| secret | `DB_PASSWORD`    | `openssl rand -base64 32` |
| secret | `REDIS_PASSWORD` | `openssl rand -base64 32` |
| var    | `APP_NAME`       | `Laravel Staging` |
| var    | `APP_ENV`        | `staging` |
| var    | `APP_DEBUG`      | `true` |
| var    | `APP_URL`        | `https://laravel-staging.example.com` |
| var    | `LOG_LEVEL`      | `debug` |

### Уровень репозитория → Environment `production`

Settings → Environments → New environment → `production`. **Включить Required reviewers** (минимум 1).

| Тип | Имя | Пример значения |
|-----|-----|-----------------|
| secret | `APP_KEY`        | `base64:...` (другое значение, не равное staging) |
| secret | `DB_PASSWORD`    | другое значение |
| secret | `REDIS_PASSWORD` | другое значение |
| var    | `APP_NAME`       | `Laravel` |
| var    | `APP_ENV`        | `production` |
| var    | `APP_DEBUG`      | `false` |
| var    | `APP_URL`        | `https://laravel.example.com` |
| var    | `LOG_LEVEL`      | `warning` |

## Первый запуск — что сделать руками

1. Поднять gitops-стек на сервере (см. README в [devops-patterns/gitops](https://github.com/devops-patterns/gitops)). После этого на сервере существует `/opt/stacks/laravel/docker-compose.yml`.
2. В GitHub-репозитории завести environment-секреты для нужного окружения (см. таблицы выше).
3. **Staging**: пушнуть в `master` (либо запустить `deploy-staging.yml` → `Run workflow`). CI автоматически создаст Docker secrets, задеплоит стек и запустит миграции.
4. **Production**: создать первый git-тег
   ```bash
   git tag v0.1.0
   git push --tags
   ```
   В Actions появится `deploy-production` со статусом **Waiting for review**. Подтвердить апрув — деплой пойдёт.
5. После первого prod-деплоя сделать базовый бэкап:
   ```bash
   ssh <deploy-user>@<prod-host> "/opt/backups/scripts/backup-laravel.sh initial"
   ```

После этого:
- Push в `master` → авто-деплой staging.
- `git tag vX.Y.Z && git push --tags` → деплой production с ручным апрувом.

## Rollback

Откат на предыдущий тег делается через `workflow_dispatch`:

```
GitHub → Actions → deploy-production → Run workflow → tag: v1.0.2 (предыдущий)
```

Откат БД (если нужен) — из pre-deploy бэкапа `/opt/backups/laravel/pre-deploy-v1.0.3_*/db.dump` через `make restore-db ENV=production` в gitops-репозитории.

## Локальная сборка и проверка образа

```bash
docker build -t laravel-test .

docker run --rm -p 8080:80 \
    -e APP_KEY="base64:$(openssl rand -base64 32)" \
    -e APP_ENV=local \
    -e APP_DEBUG=false \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=:memory: \
    -e CACHE_STORE=file \
    -e SESSION_DRIVER=file \
    -e QUEUE_CONNECTION=sync \
    laravel-test

curl -fsS http://localhost:8080/up && echo OK
```

## Что НЕ делает этот проект

- Не содержит `docker-compose.yml` для production — им управляет gitops.
- Не кладёт секреты в образ или `.env` — используются Docker Swarm secrets.
- Не запускает миграции в `ENTRYPOINT` — это явный шаг deploy job (иначе две реплики подерутся).
- Не кэширует config/route/view на этапе `docker build` — без рантайм-переменных это даст битый кэш.
