#!/usr/bin/env bash
# Runs the Bookflow test suite against this site's WordPress/WooCommerce
# install via the Local by Flywheel bundled PHP CLI. Adjust PHP_DIR and
# DB_PORT if the site's PHP version or MySQL port changes (check
# Local's sites.json or Local > Site > Database tab for the current port).
set -e

PHP_DIR="/c/Users/Nabivogedu/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
DB_PORT=10120

"$PHP_DIR/php.exe" \
  -d "extension_dir=$PHP_DIR/ext" \
  -d "extension=mysqli" \
  -d "extension=curl" \
  -d "extension=openssl" \
  -d "extension=mbstring" \
  -d "mysqli.default_port=$DB_PORT" \
  -d "mysqli.default_host=127.0.0.1" \
  "$(dirname "$0")/run-tests.php"
