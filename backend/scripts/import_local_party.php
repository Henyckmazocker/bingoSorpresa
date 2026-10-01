<?php

declare(strict_types=1);

/**
 * Importa la fiesta de casa (las fotos de `frontend/public/media/recuerdos/`) como un bingo de la
 * cuenta indicada: «Bingo Sorpresa (casa)», con números, fotos 4×5 y plan 15/4/55, y un item de
 * imagen por recuerdo con `label = texto`, en el orden del JSON.
 *
 * El JSON lo genera `node frontend/scripts/export-local-party.mjs > memories.json`. El contenedor
 * no ve `frontend/`: se copian antes JSON y fotos dentro (y se borran después).
 *
 *   docker cp memories.json <contenedor-backend>:/tmp/memories.json
 *   docker cp frontend/public/media/recuerdos <contenedor-backend>:/tmp/recuerdos
 *   docker compose exec -u www-data backend php scripts/import_local_party.php \
 *       --email=<email de la cuenta> --json=/tmp/memories.json --dir=/tmp/recuerdos
 *
 * La cuenta tiene que existir (haber entrado una vez con Google). Cada foto pasa por
 * `ImageUploadService`, el mismo camino que `upload_image`: guardas de ImageProcessor, reencodado a
 * WebP, deduplicación por (usuario, sha256) y cuota de 200 MB.
 *
 * NO es repetible: si la cuenta ya tiene un bingo con ese título, no toca nada y sale con 1 (bórralo
 * o renómbralo en Construir para volver a importar). Si algo falla a mitad, borra el bingo a medias
 * con `delete_bingo` (que también libera las fotos y la cuota) y sale con 1: no quedan restos.
 *
 * Salida: 0 hecho · 1 error (nada importado) · 2 uso incorrecto.
 */

use App\Controllers\BingoController;
use App\Domain\Exceptions\ImageRejectedException;
use App\Domain\Exceptions\QuotaExceededException;
use App\Domain\Services\ImageUploadService;

const PARTY_TITLE = 'Bingo Sorpresa (casa)';
const PARTY_PLAN = ['lead_in' => 15, 'min_gap' => 4, 'spread_over' => 55];
const LABEL_MAX = 120;

$container = require __DIR__ . '/bootstrap_cli.php';

$opts = cli_options(
    ['email', 'json', 'dir'],
    'php scripts/import_local_party.php --email=<email> --json=<memories.json> --dir=<carpeta de las fotos>'
);

// ---- 1. Validar TODO antes de escribir nada ----
$raw = @file_get_contents($opts['json']);
if ($raw === false) {
    cli_fail("No se puede leer {$opts['json']}.");
}
$entries = json_decode($raw, true);
if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
    cli_fail("{$opts['json']} no es una lista JSON de recuerdos.");
}
$dir = rtrim($opts['dir'], '/');
if (!is_dir($dir)) {
    cli_fail("No existe la carpeta {$dir}.");
}
$photos = [];
foreach ($entries as $i => $e) {
    $texto = is_array($e) && is_string($e['texto'] ?? null) ? trim($e['texto']) : '';
    $foto = is_array($e) && is_string($e['foto'] ?? null) ? basename($e['foto']) : '';
    $where = '#' . ($i + 1) . ' (' . (is_array($e) ? ($e['id'] ?? '?') : '?') . ')';
    if ($texto === '' || mb_strlen($texto) > LABEL_MAX) {
        cli_fail("{$where}: el texto tiene que tener entre 1 y " . LABEL_MAX . ' caracteres.');
    }
    if ($foto === '' || !is_file("{$dir}/{$foto}") || !is_readable("{$dir}/{$foto}")) {
        cli_fail("{$where}: no existe o no se puede leer {$dir}/{$foto}.");
    }
    $photos[] = ['texto' => $texto, 'path' => "{$dir}/{$foto}", 'foto' => $foto];
}

$db = $container->get(PDO::class);
$stmt = $db->prepare('SELECT id FROM users WHERE email = :e');
$stmt->execute(['e' => mb_strtolower(trim($opts['email']))]);
$userId = $stmt->fetchColumn();
if ($userId === false) {
    cli_fail("No hay ninguna cuenta con el email {$opts['email']}: entra una vez con Google y repite.");
}
$userId = (int) $userId;

$stmt = $db->prepare('SELECT id FROM bingos WHERE user_id = :u AND title = :t');
$stmt->execute(['u' => $userId, 't' => PARTY_TITLE]);
if (($existing = $stmt->fetchColumn()) !== false) {
    cli_fail('La cuenta ya tiene «' . PARTY_TITLE . "» (bingo #{$existing}). No se importa dos veces: "
        . 'bórralo o renómbralo en Construir y repite.');
}

/** @var BingoController $bingos */
$bingos = $container->get(BingoController::class);
/** @var ImageUploadService $uploader */
$uploader = $container->get(ImageUploadService::class);

// ---- 2. Crear el bingo (mismo camino que create_bingo: límite de 30) ----
$created = $bingos->createBingo($userId, ['title' => PARTY_TITLE]);
if ($created['status'] !== 'success') {
    cli_fail("No se pudo crear el bingo: {$created['message']}");
}
$bingoId = (int) $created['data']['id'];
echo 'Bingo #' . $bingoId . ' «' . PARTY_TITLE . "» creado para la cuenta #{$userId}.\n";

$abort = function (string $why) use ($bingos, $userId, $bingoId): never {
    $undo = $bingos->deleteBingo($userId, ['bingo_id' => $bingoId]);
    $undone = $undo['status'] === 'success' ? 'borrado (fotos y cuota liberadas)' : "NO se pudo borrar: {$undo['message']}";
    cli_fail("{$why}\nBingo #{$bingoId} {$undone}. No se ha importado nada.");
};

// ---- 3. Fotos, por ImageUploadService (guardas, WebP, dedupe y cuota) ----
$total = count($photos);
$new = 0;
$dedup = 0;
foreach ($photos as $n => $p) {
    try {
        $r = $uploader->store($userId, $bingoId, $p['path'], UPLOAD_ERR_OK, $p['texto']);
    } catch (QuotaExceededException $e) {
        $abort("[{$p['foto']}] Cuota llena: {$e->getMessage()}");
    } catch (ImageRejectedException $e) {
        $abort("[{$p['foto']}] Foto rechazada ({$e->getHttpCode()}): {$e->getMessage()}");
    } catch (\Throwable $e) {
        $abort("[{$p['foto']}] " . get_class($e) . ": {$e->getMessage()}");
    }
    $r['deduplicated'] ? $dedup++ : $new++;
    printf("[%2d/%d] %s «%s»%s\n", $n + 1, $total, $p['foto'], $p['texto'], $r['deduplicated'] ? ' (ya subida)' : '');
}

// ---- 4. Modos: números + fotos 4×5 (mismo camino que update_bingo: E1–E4) ----
$updated = $bingos->updateBingo($userId, [
    'bingo_id' => $bingoId,
    'modes' => [
        'numeric' => ['enabled' => true],
        'image' => ['enabled' => true, 'rows' => 4, 'cols' => 5],
    ],
]);
if ($updated['status'] !== 'success') {
    $abort("No se pudieron activar los modos: {$updated['message']}");
}

// ---- 5. Comprobación final ----
$stmt = $db->prepare(
    'SELECT b.numeric_enabled, b.image_enabled, b.image_rows, b.image_cols, b.lead_in, b.min_gap, b.spread_over,
            (SELECT COUNT(*) FROM bingo_items i WHERE i.bingo_id = b.id AND i.kind = \'image\') AS images,
            u.storage_bytes
       FROM bingos b JOIN users u ON u.id = b.user_id WHERE b.id = :id'
);
$stmt->execute(['id' => $bingoId]);
$row = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC));
// El plan 15/4/55 son los valores por defecto de `bingos` (init.sql); la API no los cambia.
foreach (PARTY_PLAN as $col => $want) {
    if ($row[$col] !== $want) {
        $abort("El bingo tiene {$col} = {$row[$col]} y se esperaba {$want}.");
    }
}
if ($row['images'] !== $total) {
    $abort("El bingo tiene {$row['images']} fotos y se esperaban {$total}.");
}

printf(
    "Hecho: bingo #%d «%s» · números %s · fotos %d×%d · plan %d/%d/%d · %d fotos (%d nuevas, %d ya subidas) · cuota usada %.1f MB\n",
    $bingoId,
    PARTY_TITLE,
    $row['numeric_enabled'] ? 'sí' : 'no',
    $row['image_rows'],
    $row['image_cols'],
    $row['lead_in'],
    $row['min_gap'],
    $row['spread_over'],
    $row['images'],
    $new,
    $dedup,
    $row['storage_bytes'] / 1048576
);
exit(0);
