#!/usr/bin/env bash
# =============================================================================
# backup.sh — copia de seguridad de Bingo Sorpresa (BD + fotos subidas).
#
# Uso:
#   docker/backup/backup.sh
#
# Deja en $BINGO_BACKUP_DIR/<YYYY-MM-DD>/:
#   bingo_db.sql.gz   mysqldump --single-transaction de la BD (dentro del contenedor de MySQL,
#                     con la contraseña de SU entorno: aquí no se escribe ninguna)
#   uploads.tar.gz    tar del volumen de las fotos (storage/uploads del backend)
# Crea el directorio si no existe, sobrescribe el del mismo día y, solo si todo ha ido bien, borra
# los días de más de $BINGO_BACKUP_KEEP_DAYS. La restauración se prueba con restore.sh.
#
# Variables (por defecto, producción: stack `-p bingo_prod`):
#   BINGO_MYSQL_CONTAINER   contenedor de MySQL                    [bingo-mysql-prod]
#   BINGO_DB_NAME           base de datos                          [bingo_db]
#   BINGO_UPLOADS_VOLUME    volumen Docker de las fotos, o ruta    [bingo_prod_bingo_uploads_prod]
#                           absoluta del host (p. ej. el bind de dev backend/storage/uploads)
#   BINGO_BACKUP_DIR        destino                                [/media/david/Elements2/backups/bingosorpresa]
#   BINGO_BACKUP_REQUIRE_MOUNT  punto de montaje que tiene que estar montado; vacío = no se mira
#                           (sin esto, con el disco desconectado mkdir -p llenaría el disco raíz)
#                                                                  [/media/david/Elements2]
#   BINGO_BACKUP_KEEP_DAYS  días que se guardan                    [14]
#
# Cron del host (04:30):
#   30 4 * * * /home/david/Documents/workspace/bingoSorpresa/docker/backup/backup.sh >> /home/david/Documents/workspace/bingoSorpresa/docker/backup/backup.log 2>&1
#
# Prueba contra dev (sin tocar el disco externo):
#   BINGO_MYSQL_CONTAINER=bingosorpresa-mysql-1 \
#   BINGO_UPLOADS_VOLUME="$PWD/backend/storage/uploads" \
#   BINGO_BACKUP_DIR=/tmp/bingo-backup-test BINGO_BACKUP_REQUIRE_MOUNT= docker/backup/backup.sh
# =============================================================================
set -euo pipefail

MYSQL_CONTAINER="${BINGO_MYSQL_CONTAINER:-bingo-mysql-prod}"
DB_NAME="${BINGO_DB_NAME:-bingo_db}"
UPLOADS_VOLUME="${BINGO_UPLOADS_VOLUME:-bingo_prod_bingo_uploads_prod}"
BACKUP_ROOT="${BINGO_BACKUP_DIR:-/media/david/Elements2/backups/bingosorpresa}"
REQUIRE_MOUNT="${BINGO_BACKUP_REQUIRE_MOUNT-/media/david/Elements2}"
KEEP_DAYS="${BINGO_BACKUP_KEEP_DAYS:-14}"
HELPER_IMAGE="alpine:latest"

log() { echo "[$(date '+%F %T')] backup: $*"; }
die() { echo "[$(date '+%F %T')] backup: ERROR: $*" >&2; exit 1; }

[[ "$KEEP_DAYS" =~ ^[1-9][0-9]*$ ]] || die "BINGO_BACKUP_KEEP_DAYS no es un número: $KEEP_DAYS"

# Una sola copia a la vez (cron + una manual).
exec 9>"/tmp/bingo-backup.lock"
flock -n 9 || die "ya hay otra copia en marcha"

if [[ -n "$REQUIRE_MOUNT" ]] && ! mountpoint -q "$REQUIRE_MOUNT"; then
    die "$REQUIRE_MOUNT no está montado: no se hace la copia"
fi
[[ "$(docker inspect -f '{{.State.Running}}' "$MYSQL_CONTAINER" 2>/dev/null)" == "true" ]] \
    || die "el contenedor $MYSQL_CONTAINER no está corriendo"
if [[ "$UPLOADS_VOLUME" == /* ]]; then
    [[ -d "$UPLOADS_VOLUME" ]] || die "no existe el directorio $UPLOADS_VOLUME"
else
    docker volume inspect "$UPLOADS_VOLUME" >/dev/null 2>&1 || die "no existe el volumen $UPLOADS_VOLUME"
fi

DAY="$(date +%F)"
DEST="$BACKUP_ROOT/$DAY"
mkdir -p "$DEST"
TMP_DB="$DEST/.bingo_db.sql.gz.tmp"
TMP_UP="$DEST/.uploads.tar.gz.tmp"
trap 'rm -f "$TMP_DB" "$TMP_UP"' EXIT

# --- 1. BD: mysqldump dentro del contenedor, contraseña desde SU entorno (MYSQL_PWD, no en argv) ---
log "mysqldump $DB_NAME ($MYSQL_CONTAINER) → $DEST/bingo_db.sql.gz"
docker exec "$MYSQL_CONTAINER" sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 "$1"' \
    sh "$DB_NAME" | gzip -9 > "$TMP_DB"
gzip -t "$TMP_DB" || die "el dump comprimido está roto"
zcat "$TMP_DB" | tail -n 1 | grep -q '^-- Dump completed' || die "el dump no terminó (falta «-- Dump completed»)"
mv -f "$TMP_DB" "$DEST/bingo_db.sql.gz"

# --- 2. Fotos: tar del volumen (o directorio), montado en solo lectura en un contenedor efímero ---
log "tar $UPLOADS_VOLUME → $DEST/uploads.tar.gz"
docker run --rm --network none -v "$UPLOADS_VOLUME":/data:ro "$HELPER_IMAGE" \
    tar -C /data -czf - . > "$TMP_UP"
gzip -t "$TMP_UP" || die "el tar comprimido está roto"
mv -f "$TMP_UP" "$DEST/uploads.tar.gz"

log "hecho: $(du -h "$DEST/bingo_db.sql.gz" | cut -f1) BD, $(du -h "$DEST/uploads.tar.gz" | cut -f1) fotos, $(tar -tzf "$DEST/uploads.tar.gz" | grep -c '\.webp$' || true) ficheros .webp"

# --- 3. Rotación: solo directorios con nombre de fecha, y solo tras una copia buena ---
CUTOFF="$(date -d "-$((KEEP_DAYS - 1)) days" +%F)"
for dir in "$BACKUP_ROOT"/*/; do
    name="$(basename "$dir")"
    [[ "$name" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || continue
    if [[ "$name" < "$CUTOFF" ]]; then
        log "rotación: borro $name (se guardan $KEEP_DAYS días, desde $CUTOFF)"
        rm -rf -- "$BACKUP_ROOT/$name"
    fi
done
