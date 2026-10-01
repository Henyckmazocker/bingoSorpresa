#!/usr/bin/env bash
# =============================================================================
# restore.sh — prueba de restauración de un backup de backup.sh en un stack desechable.
#
# Uso:
#   docker/backup/restore.sh <YYYY-MM-DD> [--keep]
#
# 1. Levanta `-p bingo_restore_test` (docker-compose.restore.yml: MySQL + volumen de fotos; sin
#    puertos ni red bingo_prod) desde cero.
# 2. Carga bingo_db.sql.gz y extrae uploads.tar.gz en el volumen.
# 3. Comprueba:
#    - cada fila de `uploads` restaurada tiene su foto y su miniatura en el volumen restaurado;
#    - SELECT COUNT(*) FROM bingos (y users, uploads, bingo_items) coincide con la BD de origen;
#    - el sha256sum de una foto restaurada coincide con el de la misma foto en el volumen de origen.
#    Las dos últimas comparan con el ORIGEN EN VIVO: si ha cambiado desde la copia, pueden diferir.
# 4. Derriba el stack (`down -v`), salvo con --keep (entonces imprime el comando para derribarlo).
# Sale con 0 si todo coincide y con 1 si algo no.
#
# Variables (por defecto, producción; las mismas que backup.sh):
#   BINGO_BACKUP_DIR        donde están las copias       [/media/david/Elements2/backups/bingosorpresa]
#   BINGO_MYSQL_CONTAINER   MySQL de origen (comparar)   [bingo-mysql-prod]
#   BINGO_UPLOADS_VOLUME    fotos de origen (comparar)   [bingo_prod_bingo_uploads_prod]
#   BINGO_DB_NAME           base de datos                [bingo_db]
#   BINGO_RESTORE_PROJECT   proyecto del stack desechable [bingo_restore_test]
#
# Prueba contra dev:
#   BINGO_BACKUP_DIR=/tmp/bingo-backup-test BINGO_MYSQL_CONTAINER=bingosorpresa-mysql-1 \
#   BINGO_UPLOADS_VOLUME="$PWD/backend/storage/uploads" docker/backup/restore.sh "$(date +%F)"
# =============================================================================
set -euo pipefail

usage() { echo "Uso: $0 <YYYY-MM-DD> [--keep]" >&2; exit 2; }
[[ $# -ge 1 && "$1" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || usage
DAY="$1"
KEEP=0
[[ "${2:-}" == "--keep" ]] && KEEP=1
[[ $# -le 2 && ( $# -eq 1 || $KEEP -eq 1 ) ]] || usage

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_ROOT="${BINGO_BACKUP_DIR:-/media/david/Elements2/backups/bingosorpresa}"
SRC_MYSQL="${BINGO_MYSQL_CONTAINER:-bingo-mysql-prod}"
SRC_UPLOADS="${BINGO_UPLOADS_VOLUME:-bingo_prod_bingo_uploads_prod}"
export BINGO_DB_NAME="${BINGO_DB_NAME:-bingo_db}"
PROJECT="${BINGO_RESTORE_PROJECT:-bingo_restore_test}"
SRC="$BACKUP_ROOT/$DAY"
HELPER_IMAGE="alpine:latest"

[[ "$PROJECT" != "bingo_prod" && "$PROJECT" != "bingosorpresa" ]] || { echo "El proyecto $PROJECT no es desechable." >&2; exit 2; }

log() { echo "[$(date '+%F %T')] restore: $*"; }
die() { echo "[$(date '+%F %T')] restore: ERROR: $*" >&2; exit 1; }

[[ -f "$SRC/bingo_db.sql.gz" && -f "$SRC/uploads.tar.gz" ]] || die "faltan bingo_db.sql.gz o uploads.tar.gz en $SRC"
gzip -t "$SRC/bingo_db.sql.gz" && gzip -t "$SRC/uploads.tar.gz" || die "copia corrupta en $SRC"

# Contraseña de usar y tirar para el MySQL desechable (no es la de ningún entorno real).
BINGO_RESTORE_DB_PASSWORD="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
export BINGO_RESTORE_DB_PASSWORD
dc() { docker compose -p "$PROJECT" -f "$SCRIPT_DIR/docker-compose.restore.yml" "$@"; }
# Ejecuta SQL en el MySQL indicado (contenedor) con la contraseña de root de SU entorno.
sql_in() { docker exec -i "$1" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$1"' sh "$BINGO_DB_NAME"; }

teardown() {
    if [[ $KEEP -eq 1 ]]; then
        log "stack conservado. Para derribarlo: docker compose -p $PROJECT -f $SCRIPT_DIR/docker-compose.restore.yml down -v"
    else
        log "derribando el stack $PROJECT (down -v)"
        dc down -v --remove-orphans >/dev/null 2>&1 || true
    fi
}

# --- 1. Stack desde cero ---
log "levantando $PROJECT desde cero"
dc down -v --remove-orphans >/dev/null 2>&1 || true
trap teardown EXIT
UP_OUT="$(dc up -d --quiet-pull 2>&1)" || { echo "$UP_OUT" >&2; die "no se pudo levantar $PROJECT"; }
MYSQL_C="$(dc ps -q mysql)"
FILES_C="$(dc ps -q files)"

# El entrypoint arranca primero un MySQL temporal sin red; el definitivo es el que acepta TCP.
log "esperando a MySQL"
for _ in $(seq 1 90); do
    if docker exec "$MYSQL_C" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin -uroot -h127.0.0.1 --protocol=TCP ping' >/dev/null 2>&1; then
        break
    fi
    sleep 2
done
docker exec "$MYSQL_C" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin -uroot -h127.0.0.1 --protocol=TCP ping' >/dev/null 2>&1 \
    || die "MySQL del stack de restore no arranca"

# --- 2. Restaurar ---
log "cargando $SRC/bingo_db.sql.gz"
gunzip -c "$SRC/bingo_db.sql.gz" | sql_in "$MYSQL_C"
log "extrayendo $SRC/uploads.tar.gz"
docker exec -i "$FILES_C" tar -C /data -xzpf - < "$SRC/uploads.tar.gz"

# --- 3. Comprobaciones ---
FAIL=0
check() { # check <nombre> <restaurado> <origen>
    if [[ "$2" == "$3" ]]; then
        log "OK    $1: $2"
    else
        log "FALLA $1: restaurado=$2 origen=$3"; FAIL=1
    fi
}

# 3a. Cada upload restaurado tiene sus dos ficheros.
MISSING="$(echo "SELECT CONCAT(user_id, '/', sha256) FROM uploads;" | sql_in "$MYSQL_C" \
    | docker exec -i "$FILES_C" sh -c 'n=0; while read -r p; do [ -f "/data/$p.webp" ] && [ -f "/data/${p}_t.webp" ] || n=$((n+1)); done; echo $n')"
check "uploads sin fichero restaurado" "$MISSING" "0"

# 3b. Recuentos contra la BD de origen.
if [[ "$(docker inspect -f '{{.State.Running}}' "$SRC_MYSQL" 2>/dev/null)" == "true" ]]; then
    for table in bingos users uploads bingo_items; do
        check "COUNT(*) FROM $table" \
            "$(echo "SELECT COUNT(*) FROM $table;" | sql_in "$MYSQL_C")" \
            "$(echo "SELECT COUNT(*) FROM $table;" | sql_in "$SRC_MYSQL")"
    done
else
    log "FALLA no se puede comparar: $SRC_MYSQL no está corriendo"; FAIL=1
fi

# 3c. sha256sum de una foto restaurada contra la misma en el origen.
PHOTO="$(docker exec "$FILES_C" sh -c "find /data -type f -name '*.webp' ! -name '*_t.webp' | sort | head -n 1")"
if [[ -z "$PHOTO" ]]; then
    log "AVISO la copia no tiene fotos: no hay sha256 que comparar"
else
    REL="${PHOTO#/data/}"
    RESTORED_SHA="$(docker exec "$FILES_C" sha256sum "$PHOTO" | cut -d' ' -f1)"
    ORIGIN_SHA="$(docker run --rm --network none -v "$SRC_UPLOADS":/data:ro "$HELPER_IMAGE" \
        sh -c "sha256sum '/data/$REL' 2>/dev/null | cut -d' ' -f1")"
    check "sha256sum $REL" "$RESTORED_SHA" "${ORIGIN_SHA:-<no existe en el origen>}"
fi

if [[ $FAIL -eq 0 ]]; then
    log "restauración de $DAY verificada"
else
    log "la restauración de $DAY NO coincide con el origen"
fi
exit $FAIL
