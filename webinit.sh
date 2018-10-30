#!/bin/bash

if [ ! -f .env ]; then
  echo '[i] copy env file'
  cp .env.example .env || true
  chmod 775 .env || true

  if [ -f .env ]; then
    echo '[i] generate encryption key'
    php artisan key:generate

    echo '[i] optimize production'
    composer app:production
    chmod -R 775 storage/framework/ || true
    chmod -R 775 storage/logs/ || true
    chmod -R 775 bootstrap/cache/ || true
    composer install --no-dev --optimize-autoloader
  fi
fi

#> /dev/null 2>&1

echo 'OK'
