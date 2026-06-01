#!/bin/sh
set -e

if [ -f /var/www/html/migrate.php ]; then
  echo "Running database migrations..."
  php /var/www/html/migrate.php || echo "Warning: migration step failed. Continuing to start Apache."
fi

exec "${@:-apache2-foreground}"
