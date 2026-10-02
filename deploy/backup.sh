#!/bin/sh
# Writes a consistent, compressed dump of the Keelwatch database.
#
#   deploy/backup.sh [directory]      (default: ./backups)
#
# Run from anywhere; uses the compose project in the repository root. The dump
# is taken with --single-transaction, so the stack keeps running. Files are
# created readable only by the current user.
set -eu
cd "$(dirname "$0")/.."
dir=${1:-backups}
umask 077
mkdir -p "$dir"
file="$dir/keelwatch-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"

docker compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --single-transaction --quick --no-tablespaces \
     --routines --triggers -u"$MYSQL_USER" "$MYSQL_DATABASE"' \
  | gzip > "$file.partial"
mv "$file.partial" "$file"
echo "$file"
