#!/usr/bin/env bash
set -Eeuo pipefail

# Installiert/aktualisiert Work Tracker in einer Nextcloud-AIO-Installation.
# Auf dem Docker-Host ausführen, nicht innerhalb des Nextcloud-Containers.

APP_ID="worktracker"
REPO="https://github.com/rankerson/nextcloud-worktracker.git"
BRANCH="main"
CONTAINER="${NEXTCLOUD_CONTAINER:-nextcloud-aio-nextcloud}"
APP_DIR="/var/www/html/custom_apps/${APP_ID}"
WORK_DIR="$(mktemp -d)"
BACKUP_DIR="${WORKTRACKER_BACKUP_DIR:-/var/backups/nextcloud-worktracker}"
BACKUP_FILE=""
MAINTENANCE_ENABLED=0
INSTALL_STARTED=0

log() { printf '[worktracker] %s\n' "$*"; }
fail() { log "FEHLER: $*" >&2; exit 1; }

cleanup() {
  local rc=$?
  if (( rc != 0 && INSTALL_STARTED )); then
    log "Update fehlgeschlagen. Stelle den vorherigen App-Code wieder her."
    if [[ -n "$BACKUP_FILE" && -f "$BACKUP_FILE" ]]; then
      docker exec -u www-data "$CONTAINER" rm -rf "$APP_DIR" || true
      docker cp "$BACKUP_FILE" "$CONTAINER:$APP_DIR" || true
      docker exec -u www-data "$CONTAINER" php occ app:enable "$APP_ID" >/dev/null 2>&1 || true
    elif [[ -d "$APP_DIR" ]]; then
      docker exec -u www-data "$CONTAINER" rm -rf "$APP_DIR" || true
    fi
  fi
  if (( MAINTENANCE_ENABLED )); then
    docker exec -u www-data "$CONTAINER" php occ maintenance:mode --off || true
  fi
  rm -rf "$WORK_DIR"
  exit "$rc"
}
trap cleanup EXIT

command -v docker >/dev/null 2>&1 || fail "Docker wurde nicht gefunden."
docker inspect "$CONTAINER" >/dev/null 2>&1 || fail "Nextcloud-Container '$CONTAINER' nicht gefunden. NEXTCLOUD_CONTAINER setzen."
docker exec "$CONTAINER" test -f /var/www/html/occ || fail "Im Container wurde /var/www/html/occ nicht gefunden."
command -v git >/dev/null 2>&1 || fail "git wurde nicht gefunden."

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

log "Prüfe Nextcloud und App-Verzeichnis."
docker exec -u www-data "$CONTAINER" php occ status
docker exec -u www-data "$CONTAINER" php occ app:list | grep -q "$APP_ID" && log "Vorhandene App-Installation erkannt." || true

log "Lade Branch '$BRANCH'."
git clone --depth 1 --branch "$BRANCH" "$REPO" "$WORK_DIR/repo"
[[ -f "$WORK_DIR/repo/appinfo/info.xml" ]] || fail "Download enthält keine gültige Nextcloud-App."
grep -q '<id>worktracker</id>' "$WORK_DIR/repo/appinfo/info.xml" || fail "App-ID stimmt nicht."

if docker exec "$CONTAINER" test -d "$APP_DIR"; then
  BACKUP_FILE="$WORK_DIR/worktracker-backup"
  docker cp "$CONTAINER:$APP_DIR" "$BACKUP_FILE"
  STAMP="$(date +%Y%m%d-%H%M%S)"
  tar -C "$WORK_DIR" -czf "$BACKUP_DIR/worktracker-$STAMP.tar.gz" worktracker-backup
  log "Code-Backup erstellt: $BACKUP_DIR/worktracker-$STAMP.tar.gz"
fi

INSTALL_STARTED=1
docker exec -u www-data "$CONTAINER" php occ maintenance:mode --on
MAINTENANCE_ENABLED=1

docker exec -u www-data "$CONTAINER" rm -rf "$APP_DIR"
docker cp "$WORK_DIR/repo" "$CONTAINER:$APP_DIR"
docker exec -u www-data "$CONTAINER" chown -R www-data:www-data "$APP_DIR"

# Führt gegebenenfalls ausstehende Nextcloud-/App-Migrationen aus.
docker exec -u www-data "$CONTAINER" php occ upgrade
docker exec -u www-data "$CONTAINER" php occ app:enable "$APP_ID"
docker exec -u www-data "$CONTAINER" php occ app:list | grep -q "$APP_ID" || fail "App konnte nicht aktiviert werden."

log "Work Tracker wurde erfolgreich aus '$BRANCH' installiert/aktualisiert."
