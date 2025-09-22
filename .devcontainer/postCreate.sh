#!/usr/bin/env bash
set -euo pipefail

echo "[postCreate] Updating apt and installing system deps"
sudo apt-get update -y
sudo apt-get install -y \
  libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
  libzip-dev zip unzip imagemagick ghostscript libmagickwand-dev \
  python3-opencv sqlite3

echo "[postCreate] Installing PHP extensions (gd, zip, mbstring, exif, pdo_sqlite)"
if command -v docker-php-ext-install >/dev/null 2>&1; then
  sudo docker-php-ext-configure gd --with-freetype --with-jpeg || true
  sudo docker-php-ext-install -j"$(nproc)" gd zip mbstring exif pdo_sqlite || true
else
  echo "docker-php-ext-install not found; installing PHP extensions via apt where possible"
  sudo apt-get install -y php-gd php-zip php-mbstring php-exif php-sqlite3 || true
fi

echo "[postCreate] Composer install"
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')" && \
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
  ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")" && \
  [ "$EXPECTED_CHECKSUM" = "$ACTUAL_CHECKSUM" ] && \
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
  rm composer-setup.php || \
  (echo 'ERROR: Invalid composer installer checksum' && rm composer-setup.php && exit 1)
fi

composer install --no-interaction --prefer-dist

echo "[postCreate] NPM install"
npm install

echo "[postCreate] Python dependencies"
if [ -f requirements.txt ]; then
  pip3 install -r requirements.txt
fi

echo "[postCreate] Preparing Laravel app (.env, storage, key)"
if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi
echo "[postCreate] Configuring .env for SQLite and database queue"
if grep -q '^DB_CONNECTION=' .env; then
  sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
else
  echo 'DB_CONNECTION=sqlite' >> .env
fi
if grep -q '^DB_DATABASE=' .env; then
  sed -i 's#^DB_DATABASE=.*#DB_DATABASE=/workspace/database/database.sqlite#' .env
else
  echo 'DB_DATABASE=/workspace/database/database.sqlite' >> .env
fi
if grep -q '^QUEUE_CONNECTION=' .env; then
  sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=database/' .env
else
  echo 'QUEUE_CONNECTION=database' >> .env
fi
mkdir -p database
if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
fi
php artisan storage:link || true
mkdir -p storage/framework/{cache,sessions,views} storage/app/pdf-exports/_cache
chmod -R 777 storage bootstrap/cache || true

php artisan key:generate --force || true

echo "[postCreate] Running database migrations"
php artisan migrate --force || true

echo "[postCreate] All set!"
