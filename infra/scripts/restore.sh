#!/usr/bin/env bash
# Restore a snapshot created by backup.sh into the production PostgreSQL
# database and photobank_storage volume. Run from the repo root via
# `make restore SNAPSHOT=<timestamp>`.
#
# Destructive: overwrites the current database and photo storage contents.
# Stop api/api-worker before restoring so nothing writes during the restore.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

SNAPSHOT="${1:-${SNAPSHOT:-}}"
if [ -z "${SNAPSHOT}" ]; then
  echo "Usage: infra/scripts/restore.sh <snapshot-timestamp>" >&2
  echo "Available snapshots in ${BACKUP_DIR:-./backups}:" >&2
  ls -1 "${BACKUP_DIR:-./backups}" 2>/dev/null >&2 || true
  exit 1
fi

if [[ ! "${SNAPSHOT}" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
  echo "Snapshot must be a UTC timestamp such as 20260925T150000Z" >&2
  exit 1
fi

read -r -p "This overwrites the current database and photo storage with snapshot '${SNAPSHOT}'. Type 'yes' to continue: " CONFIRM
if [ "${CONFIRM}" != "yes" ]; then
  echo "Cancelled"
  exit 1
fi

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile backup)

"${COMPOSE[@]}" run --rm --no-deps backup-tools sh -c "
  set -e
  test -f /backups/${SNAPSHOT}/db.dump || { echo 'db.dump not found for snapshot ${SNAPSHOT}' >&2; exit 1; }
  test -f /backups/${SNAPSHOT}/photos.tar.gz || { echo 'photos.tar.gz not found for snapshot ${SNAPSHOT}' >&2; exit 1; }
  echo '==> Restoring PostgreSQL database'
  pg_restore -h \"\${DB_HOST}\" -U \"\${DB_USERNAME}\" -d \"\${DB_DATABASE}\" --clean --if-exists /backups/${SNAPSHOT}/db.dump
  echo '==> Restoring photo storage'
  rm -rf /data/photos/*
  tar xzf /backups/${SNAPSHOT}/photos.tar.gz -C /data
  echo '==> Restore complete'
"
