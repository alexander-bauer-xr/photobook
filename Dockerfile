FROM php:8.3-fpm-bookworm AS app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        git \
        gosu \
        unzip \
        zip \
        libicu-dev \
        libzip-dev \
        libsqlite3-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        python3 \
        python3-pip \
        python3-venv \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        pcntl \
        pdo \
        pdo_sqlite \
        sqlite3 \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN composer dump-autoload --optimize \
    && npm run build \
    && npx playwright install --with-deps chromium \
    && npm prune --omit=dev \
    && npm cache clean --force \
    && python3 -m venv /var/www/html/.venv \
    && /var/www/html/.venv/bin/pip install --no-cache-dir --upgrade pip \
    && /var/www/html/.venv/bin/pip install --no-cache-dir -r scripts/requirements.txt \
    && mkdir -p \
        storage/app \
        storage/app/pdf-exports \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/database \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /ms-playwright /var/www/html/.venv

COPY docker/php/php.ini /usr/local/etc/php/conf.d/photobook.ini
COPY docker/php/entrypoint.sh /usr/local/bin/photobook-entrypoint

RUN chmod +x /usr/local/bin/photobook-entrypoint

ENTRYPOINT ["photobook-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS nginx

WORKDIR /var/www/html

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
