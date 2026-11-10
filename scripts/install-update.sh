#!/usr/bin/env bash
set -Eeuo pipefail

# Auf dem Docker-Host ausführen. Standard ist der Nextcloud-AIO-Container.
APP_ID="worktracker"
REPO="https://github.com/rankerson/nextcloud-worktracker.git"
BRANCH="main"
CONTAINER="${NEXTCLOUD_CONTAINER:-nextcloud-aio-nextcloud}"
APP_ROOT="/var/www/html/custom_apps"
APP_DIR="${APP_ROOT}/${APP_ID}"
WORK_DIR="$(mktemp -d)"
BACKUP_DIR="${WORKTRACKER_BACKUP_DIR:-/var/backups/nextcloud-worktracker}"
BACKUP_FILE=""
CONTAINER_ARCHIVE="/tmp/worktracker-install-$$.tar.gz"
MAINTENANCE_ENABLED=0
INSTALL_STARTED=0
MIGRATION_STARTED=0

log() { printf '[worktracker] %s\n' "$*"; }
fail() { log "FEHLER: $*" >&2; exit 1; }

cleanup() {
  local rc=$?
  if (( rc != 0 && INSTALL_STARTED )); then
    if (( MIGRATION_STARTED )); then
      log "Fehler während/nach der Migrationsphase. Der neue App-Code bleibt installiert, da ein Code-Rollback bei möglicherweise bereits geänderten Datenbanktabellen inkonsistent wäre."
    else
      log "Installation fehlgeschlagen. Stelle den vorherigen App-Code wieder her."
      docker exec "$CONTAINER" rm -rf "$APP_DIR" || true
      if [[ -n "$BACKUP_FILE" && -f "$BACKUP_FILE" ]]; then
        docker cp "$BACKUP_FILE" "$CONTAINER:$CONTAINER_ARCHIVE" || true
        docker exec "$CONTAINER" tar -xzf "$CONTAINER_ARCHIVE" -C "$APP_ROOT" || true
        docker exec "$CONTAINER" rm -f "$CONTAINER_ARCHIVE" || true
      fi
    fi
    # occ upgrade verwaltet den Wartungsmodus selbst. Bei einem Fehler
    # kann er dennoch aktiv bleiben, daher hier ausdrücklich ausschalten.
    docker exec -u www-data "$CONTAINER" php occ maintenance:mode --off || true
    MAINTENANCE_ENABLED=0
    if [[ -n "$BACKUP_FILE" && -f "$BACKUP_FILE" ]] && (( ! MIGRATION_STARTED )); then
      docker exec -u www-data "$CONTAINER" php occ app:enable "$APP_ID" >/dev/null 2>&1 || true
    fi
  elif (( MAINTENANCE_ENABLED )); then
    docker exec -u www-data "$CONTAINER" php occ maintenance:mode --off || true
  fi
  docker exec "$CONTAINER" rm -f "$CONTAINER_ARCHIVE" >/dev/null 2>&1 || true
  rm -rf "$WORK_DIR"
  exit "$rc"
}
trap cleanup EXIT

command -v docker >/dev/null 2>&1 || fail "Docker wurde nicht gefunden."
command -v git >/dev/null 2>&1 || fail "git wurde nicht gefunden."
docker inspect "$CONTAINER" >/dev/null 2>&1 || fail "Nextcloud-Container '$CONTAINER' nicht gefunden. NEXTCLOUD_CONTAINER setzen."
docker exec "$CONTAINER" test -f /var/www/html/occ || fail "Im Container wurde /var/www/html/occ nicht gefunden."

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

log "Prüfe Nextcloud."
docker exec -u www-data "$CONTAINER" php occ status

log "Lade Branch '$BRANCH'."
git clone --depth 1 --branch "$BRANCH" "$REPO" "$WORK_DIR/repo"
[[ -f "$WORK_DIR/repo/appinfo/info.xml" ]] || fail "Download enthält keine gültige Nextcloud-App."
grep -q '<id>worktracker</id>' "$WORK_DIR/repo/appinfo/info.xml" || fail "App-ID stimmt nicht."
tar -C "$WORK_DIR/repo" --exclude=.git -czf "$WORK_DIR/worktracker.tar.gz" .

if docker exec "$CONTAINER" test -d "$APP_DIR"; then
  STAMP="$(date +%Y%m%d-%H%M%S)"
  BACKUP_FILE="$BACKUP_DIR/worktracker-$STAMP.tar.gz"
  docker exec "$CONTAINER" tar -czf "$CONTAINER_ARCHIVE" -C "$APP_ROOT" "$APP_ID"
  docker cp "$CONTAINER:$CONTAINER_ARCHIVE" "$BACKUP_FILE"
  docker exec "$CONTAINER" rm -f "$CONTAINER_ARCHIVE"
  log "Code-Backup erstellt: $BACKUP_FILE"
fi

INSTALL_STARTED=1
docker exec -u www-data "$CONTAINER" php occ maintenance:mode --on
MAINTENANCE_ENABLED=1

docker cp "$WORK_DIR/worktracker.tar.gz" "$CONTAINER:$CONTAINER_ARCHIVE"
docker exec "$CONTAINER" rm -rf "$APP_DIR"
docker exec "$CONTAINER" mkdir -p "$APP_DIR"
docker exec "$CONTAINER" tar -xzf "$CONTAINER_ARCHIVE" -C "$APP_DIR"
docker exec "$CONTAINER" rm -f "$CONTAINER_ARCHIVE"
# The app directory may be a Docker bind mount whose ownership cannot be
# changed from inside the container. The extracted Git permissions (dirs 755,
# files 644) are readable by www-data; verify that instead of running chown.
docker exec -u www-data "$CONTAINER" test -x "$APP_DIR"
docker exec -u www-data "$CONTAINER" test -r "$APP_DIR/appinfo/info.xml"

# occ upgrade verwaltet den Wartungsmodus selbst. Deshalb muss der
# zuvor zum Austausch des App-Codes aktivierte Wartungsmodus hier aus sein.
docker exec -u www-data "$CONTAINER" php occ maintenance:mode --off
MAINTENANCE_ENABLED=0

# Führt ausstehende Datenbankschema- und App-Migrationen aus.
# Ab hier wird bei Fehlern kein automatischer Code-Rollback mehr versucht,
# weil Datenbankmigrationen bereits teilweise ausgeführt worden sein könnten.
MIGRATION_STARTED=1
docker exec -u www-data "$CONTAINER" php occ upgrade
docker exec -u www-data "$CONTAINER" php occ app:enable "$APP_ID"
docker exec -u www-data "$CONTAINER" php occ app:list | grep -q "$APP_ID" || fail "App konnte nicht aktiviert werden."

log "Work Tracker wurde erfolgreich aus '$BRANCH' installiert/aktualisiert."
