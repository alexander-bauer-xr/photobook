#!/usr/bin/env sh
set -eu

cd /var/www/html

mkdir -p \
  storage/app \
  storage/app/pdf-exports \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  storage/database \
  bootstrap/cache

if [ ! -f .env ]; then
  if [ -f .env.example ]; then
    cp .env.example .env
  else
    cat > .env <<'ENVEOF'
APP_NAME=Photobook
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://127.0.0.1:8080
LOG_CHANNEL=stderr
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/storage/database/database.sqlite
QUEUE_CONNECTION=database
CACHE_STORE=file
SESSION_DRIVER=file
ENVEOF
  fi
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
  db_path="${DB_DATABASE:-/var/www/html/storage/database/database.sqlite}"
  mkdir -p "$(dirname "$db_path")"
  touch "$db_path"
fi

if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data storage bootstrap/cache .env
fi

run_php() {
  if [ "$(id -u)" = "0" ]; then
    gosu www-data php "$@"
  else
    php "$@"
  fi
}

run_artisan() {
  run_php artisan "$@"
}

if [ -z "${APP_KEY:-}" ] && ! grep -Eq '^APP_KEY=base64:.+' .env; then
  run_artisan key:generate --force
fi

run_artisan storage:link >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  run_artisan migrate --force
fi

if [ "$(id -u)" = "0" ] && [ "${1:-}" = "php" ]; then
  exec gosu www-data "$@"
fi

exec docker-php-entrypoint "$@"
