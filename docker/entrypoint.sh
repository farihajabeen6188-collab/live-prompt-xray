#!/bin/sh
# One-command start: install dependencies on first boot (vendor/ lives in a named volume).
set -e
cd /srv/app
if [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi
mkdir -p var
exec "$@"
