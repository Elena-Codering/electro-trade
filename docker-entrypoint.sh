#!/bin/sh
set -e

# Ensure Apache (www-data) can write uploads after the bind mount is applied
mkdir -p /var/www/html/uploads/listings
chown -R www-data:www-data /var/www/html/uploads

if [ -f /var/www/html/migrate.php ]; then
  echo "Running database migrations..."
  php /var/www/html/migrate.php || echo "Warning: migration step failed. Continuing to start Apache."
fi

exec "${@:-apache2-foreground}"