#!/bin/sh
# Restores a dump made by deploy/backup.sh, replacing the current data.
#
#   deploy/restore.sh backups/keelwatch-20261002T060000Z.sql.gz
#
# Stops the api, worker and web services while restoring, then applies any
# newer migrations and starts them again.
set -eu
cd "$(dirname "$0")/.."
file=${1:?usage: deploy/restore.sh <backup.sql.gz>}
[ -f "$file" ] || { echo "No such file: $file" >&2; exit 1; }

printf 'This replaces ALL data in the Keelwatch database with %s. Type "restore" to continue: ' "$file"
read -r answer
[ "$answer" = "restore" ] || { echo "Cancelled."; exit 1; }

docker compose stop web worker api
gunzip -c "$file" | docker compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"'
docker compose up -d
echo "Restored $file."
