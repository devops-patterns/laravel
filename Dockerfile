# syntax=docker/dockerfile:1.7

# ============================================================================
# Stage 1: build — собирает composer vendor и public/build (Vite + Wayfinder)
# ============================================================================
FROM dunglas/frankenphp:1-php8.5-bookworm AS build

RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        intl \
        zip \
        opcache \
        bcmath \
        pcntl \
        @composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates gnupg \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative \
    && npm run build \
    && rm -rf node_modules \
    && chmod -R ug+rwX storage bootstrap/cache

# ============================================================================
# Stage 2: runtime — минимальный FrankenPHP образ для production
# ============================================================================
FROM dunglas/frankenphp:1-php8.5-bookworm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        postgresql-client \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        intl \
        zip \
        opcache \
        bcmath \
        pcntl

WORKDIR /app

COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

COPY --from=build --chown=www-data:www-data /app /app

ENV SERVER_NAME=:80

EXPOSE 80

HEALTHCHECK NONE

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
