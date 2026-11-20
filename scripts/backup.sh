#!/usr/bin/env bash
#
# Backs up the production stack: PostgreSQL, the storage volume and a
# Meilisearch dump. Intended to run from the repository root on the server,
# e.g. via a daily cron job:
#
#   crontab -e
#   15 3 * * * cd /opt/tasksbase && ./scripts/backup.sh >> /var/log/tasksbase-backup.log 2>&1
#
# Tunables (env): COMPOSE, BACKUP_DIR, RETENTION_DAYS.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="${COMPOSE:-docker compose -f docker-compose.prod.yml}"
BACKUP_DIR="${BACKUP_DIR:-$ROOT/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"

STAMP="$(date +%F-%H%M)"

mkdir -p "$BACKUP_DIR/db" "$BACKUP_DIR/storage" "$BACKUP_DIR/meili"

echo "[$(date -Is)] Backup started (stamp=$STAMP)"

# ── PostgreSQL (custom format, restorable with pg_restore) ────────────────────
$COMPOSE exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' \
    > "$BACKUP_DIR/db/db-$STAMP.dump"
echo "  db       -> db/db-$STAMP.dump"

# ── User files (storage volume) ───────────────────────────────────────────────
$COMPOSE exec -T app tar czf - -C /var/www/html/storage . \
    > "$BACKUP_DIR/storage/storage-$STAMP.tgz"
echo "  storage  -> storage/storage-$STAMP.tgz"

# ── Meilisearch dump (created inside the container, then copied out) ──────────
UID_JSON="$($COMPOSE exec -T meilisearch sh -c \
    'curl -sS -X POST -H "Authorization: Bearer $MEILI_MASTER_KEY" http://localhost:7700/dumps' || true)"
TASK_UID="$(printf '%s' "$UID_JSON" | sed -n 's/.*"uid":\([0-9][0-9]*\).*/\1/p')"

if [ -n "${TASK_UID:-}" ]; then
    STATUS=""
    for _ in $(seq 1 60); do
        STATUS="$($COMPOSE exec -T meilisearch sh -c \
            "curl -sS -H \"Authorization: Bearer \$MEILI_MASTER_KEY\" http://localhost:7700/tasks/$TASK_UID" \
            | sed -n 's/.*"status":"\([a-z]*\)".*/\1/p' || true)"
        [ "$STATUS" = "succeeded" ] && break
        [ "$STATUS" = "failed" ] && break
        sleep 2
    done

    if [ "$STATUS" = "succeeded" ]; then
        $COMPOSE cp "meilisearch:/meili_data/dumps/$TASK_UID.dump" "$BACKUP_DIR/meili/meili-$STAMP.dump"
        echo "  meili    -> meili/meili-$STAMP.dump"
    else
        echo "  meili    -> SKIPPED (dump status: ${STATUS:-timeout})" >&2
    fi
else
    echo "  meili    -> SKIPPED (no dump task uid)" >&2
fi

# ── Retention ─────────────────────────────────────────────────────────────────
find "$BACKUP_DIR" -type f -mtime +"$RETENTION_DAYS" -delete 2>/dev/null || true

echo "[$(date -Is)] Backup finished"
