#!/bin/sh
# Staging copy of the allmarket.biz portal side for testing the Seller API.
#   DUMP_DIR=".../file/backup/2017-04-19 21.48.49 uwahlpeler" tools/staging/up.sh
#   WEB_ONLY=1 tools/staging/up.sh      # recreate only the PHP container, keep the DB
# DB: mariadb (MyISAM tables from the portal's own VuCMS backup), web: PHP 7.4 + Apache
# (production runs PHP 7.4, see httpdocs/.php-version). Both join the `amtest` network,
# so the PrestaShop test container reaches the API at http://amportal-web/api/v1.
# tools/staging/core/ (not in git) holds copies of these portal files, taken from the server:
#   system/db.class.php system/db_mysqli.class.php system/tree.class.php
#   module/multitrade/features.api.php module/multitrade/features.lib.php file/cache/langs.php
set -e
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
NET=${NET:-amtest}
[ -n "$WEB_ONLY" ] || : "${DUMP_DIR:?set DUMP_DIR to a VuCMS backup folder with 1.sql, 2.sql… (or WEB_ONLY=1)}"

docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET"
docker build -q -t amportal-php74 "$HERE" >/dev/null

docker rm -f amportal-web >/dev/null 2>&1 || true
if [ -z "$WEB_ONLY" ]; then
docker rm -f amportal-db >/dev/null 2>&1 || true
docker run -d --name amportal-db --network "$NET" \
  -e MARIADB_ROOT_PASSWORD=staging -e MARIADB_DATABASE=allmarket \
  mariadb:10.11 --character-set-server=utf8 --collation-server=utf8_general_ci >/dev/null

echo "waiting for MariaDB…"
until docker exec amportal-db mariadb -uroot -pstaging -e 'select 1' allmarket >/dev/null 2>&1; do sleep 1; done

for f in "$DUMP_DIR"/*.sql; do
  echo "loading $(basename "$f")"
  docker exec -i amportal-db mariadb -uroot -pstaging --default-character-set=utf8 allmarket < "$f"
done
# Tables/columns production has but the 2017 backup lacks (DDL copied from production).
docker exec -i amportal-db mariadb -uroot -pstaging --default-character-set=utf8 allmarket < "$HERE/prod_schema_additions.sql"
fi

docker run -d --name amportal-web --network "$NET" -p 8090:80 \
  -v "$ROOT/portal/api/v1:/var/www/html/api/v1" \
  -v "$ROOT/portal/member:/var/www/html/member" \
  -v "$HERE/dbvucmsconf.inc.php:/var/www/html/dbvucmsconf.inc.php:ro" \
  -v "$HERE/harness:/var/www/html/staging" \
  -v "$HERE/core/system:/var/www/html/system:ro" \
  -v "$HERE/core/module/multitrade:/var/www/html/module/multitrade:ro" \
  -v "$HERE/vucmsconf.inc.php:/var/www/html/vucmsconf.inc.php:ro" \
  -v "$HERE/config.staging.php:/etc/amapi/config.staging.php:ro" -e AMAPI_CONFIG=/etc/amapi/config.staging.php \
  -e AMAPI_ALLOW_PRIVATE_WEBHOOKS=1 \
  amportal-php74 >/dev/null

# Writable category cache like the portal's file/cache (langs.php copied from production).
docker exec amportal-web sh -c 'mkdir -p /var/www/html/file/cache && chown www-data:www-data /var/www/html/file/cache'
docker cp "$HERE/core/file/cache/langs.php" amportal-web:/var/www/html/file/cache/langs.php

echo "API: http://localhost:8090/api/v1/ping  (from containers: http://amportal-web/api/v1)"
