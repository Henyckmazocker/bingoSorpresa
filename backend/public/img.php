<?php

declare(strict_types=1);

/**
 * Fotos subidas (FUERA del router de acciones).
 *
 * GET /img.php?t=<token>[&thumb=1] → image/webp. El token es una URL firmada
 * (`SignedUrlService::sign('img', uploadId, ownerId, ttl)`): un <img src> no puede mandar la
 * cabecera Authorization, así que el permiso viaja en la URL. 403 si el token no vale, ha caducado
 * o no casa con el upload; 404 si el upload ya no existe.
 *
 * Lee `storage/uploads/<user_id>/<sha256>.webp` (o `<sha256>_t.webp` con thumb=1).
 */

require_once __DIR__ . '/../bootstrap_min.php';

use App\Domain\Services\UploadStorage;
use App\Infrastructure\Auth\SignedUrlService;
use App\Infrastructure\Database\DatabaseConnector;

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    exit;
}

$token = $_GET['t'] ?? '';
$payload = is_string($token) && $token !== '' ? (new SignedUrlService())->verify($token) : null;
if ($payload === null || $payload['k'] !== 'img' || $payload['uid'] < 1) {
    http_response_code(403);
    exit;
}

try {
    $pdo = (new DatabaseConnector())->getConnection();
    $stmt = $pdo->prepare('SELECT user_id, sha256 FROM uploads WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $payload['id']]);
    $upload = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    http_response_code(500);
    exit;
}

if (!$upload) {
    http_response_code(404);
    exit;
}
// El token se firmó para ESTE dueño: un token válido de otro upload/usuario no sirve.
if ((int) $upload['user_id'] !== $payload['uid']) {
    http_response_code(403);
    exit;
}

$thumb = ($_GET['thumb'] ?? '') === '1';
$path = (new UploadStorage())->path((int) $upload['user_id'], (string) $upload['sha256'], $thumb);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

// El contenido de un sha256 no cambia nunca: el ETag es el propio hash (+ variante).
$etag = '"' . $upload['sha256'] . ($thumb ? '-t' : '') . '"';
header('Cache-Control: private, max-age=3600');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: image/webp');
header('Content-Length: ' . filesize($path));

while (ob_get_level() > 0) {
    ob_end_clean();
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    readfile($path);
}
