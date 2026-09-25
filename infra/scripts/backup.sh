#!/usr/bin/env bash
# Back up the production PostgreSQL database and the photobank_storage
# volume (originals + variants). Run from the repo root via `make backup`.
#
# Writes a timestamped snapshot directory under BACKUP_DIR (default
# ./backups, overridable via .env): db.dump (pg_dump custom format) and
# photos.tar.gz (tar of the photobank_storage volume).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile backup)
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"

"${COMPOSE[@]}" run --rm --no-deps backup-tools sh -c "
  set -e
  mkdir -p /backups/${TIMESTAMP}
  echo '==> Dumping PostgreSQL database'
  pg_dump -h \"\${DB_HOST}\" -U \"\${DB_USERNAME}\" -d \"\${DB_DATABASE}\" -Fc -f /backups/${TIMESTAMP}/db.dump
  echo '==> Archiving photo storage'
  tar czf /backups/${TIMESTAMP}/photos.tar.gz -C /data photos
  echo \"==> Backup complete: /backups/${TIMESTAMP}\"
"

echo "Snapshot written to ${BACKUP_DIR:-./backups}/${TIMESTAMP}"
