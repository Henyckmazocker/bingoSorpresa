<?php

declare(strict_types=1);

use App\Infrastructure\Middleware\AuthenticationMiddleware;
use App\Infrastructure\Middleware\CSRFMiddleware;
use App\Infrastructure\Middleware\LoggingMiddleware;
use App\Infrastructure\Middleware\RateLimitMiddleware;
use App\Infrastructure\Middleware\ShareTokenOrAuthMiddleware;

/**
 * Routes configuration for Bingo Sorpresa.
 *
 * Each action maps to [ControllerName, method] plus a middleware stack.
 *  - LoggingMiddleware: always.
 *  - AuthenticationMiddleware: injects user_id (session cookie or JWT bearer).
 *  - CSRFMiddleware: state-changing operations (skipped automatically for JWT auth).
 *  - ValidationMiddleware: required-field checks.
 *  - RateLimitMiddleware (M6): [RateLimitMiddleware::class, ['limit' => n, 'window' => s, 'by' => ip|user|device]].
 *    Los de 'user' van DESPUÉS de AuthenticationMiddleware (necesitan user_id); el de login, ANTES
 *    de verificar el token de Google (cuenta también los intentos fallidos). 429 + Retry-After.
 *
 * NOTE: binary endpoints (signed images) are NOT here — they are served by public/img.php,
 * authenticated via signed URL tokens.
 */
return [
    // ====================== AUTH ======================
    'login' => [
        'controller' => ['AuthController', 'login'],
        'middleware' => [LoggingMiddleware::class, [RateLimitMiddleware::class, ['limit' => 10, 'window' => 60, 'by' => 'ip']]],
    ],
    'logout' => [
        'controller' => ['AuthController', 'logout'],
        'middleware' => [LoggingMiddleware::class],
    ],
    'check_auth' => [
        'controller' => ['AuthController', 'checkAuth'],
        'middleware' => [LoggingMiddleware::class],
    ],
    'ping' => [
        'controller' => ['AuthController', 'ping'],
        'middleware' => [LoggingMiddleware::class],
    ],
    'me' => [
        'controller' => ['AuthController', 'me'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'delete_account' => [
        'controller' => ['AuthController', 'deleteAccount'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class, CSRFMiddleware::class],
    ],

    // ====================== BINGOS (BingoController) ======================
    // Todo con sesión y solo sobre bingos propios (404 si no). Lecturas sin CSRF; escrituras con él.
    'list_bingos' => [
        'controller' => ['BingoController', 'listBingos'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'get_bingo' => [
        'controller' => ['BingoController', 'getBingo'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'create_bingo' => [
        'controller' => ['BingoController', 'createBingo'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class, CSRFMiddleware::class],
    ],
    'update_bingo' => [
        'controller' => ['BingoController', 'updateBingo'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class, CSRFMiddleware::class],
    ],
    'delete_bingo' => [
        'controller' => ['BingoController', 'deleteBingo'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class, CSRFMiddleware::class],
    ],
    'duplicate_bingo' => [
        'controller' => ['BingoController', 'duplicateBingo'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class, CSRFMiddleware::class],
    ],
    'set_share' => [
        'controller' => ['BingoController', 'setShare'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class, CSRFMiddleware::class],
    ],
    'update_item' => [
        'controller' => ['BingoController', 'updateItem'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class, CSRFMiddleware::class],
    ],
    'delete_item' => [
        'controller' => ['BingoController', 'deleteItem'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class, CSRFMiddleware::class],
    ],
    // Canciones de YouTube en lote (≤50 enlaces, uno a uno contra oEmbed): 120 peticiones/h por usuario.
    'add_music_items' => [
        'controller' => ['BingoController', 'addMusicItems'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            [RateLimitMiddleware::class, ['limit' => 120, 'window' => 3600, 'by' => 'user']],
            CSRFMiddleware::class,
        ],
    ],

    // ====================== SUBIDA (UploadController) ======================
    // multipart/form-data: action, bingo_id, file (+ csrf_token con sesión). Ver Application::readInput.
    'upload_image' => [
        'controller' => ['UploadController', 'uploadImage'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            [RateLimitMiddleware::class, ['limit' => 120, 'window' => 3600, 'by' => 'user']],
            CSRFMiddleware::class,
        ],
    ],

    // ====================== JUGAR E IMPRIMIR (PlayController) ======================
    // Lecturas con cuenta y solo de bingos propios; `get_shared_payload` es público por share_token
    // (60/min por IP). `save_print` vale con cuenta (bingo_id: Auth+CSRF)
    // o con share_token (público): lo decide ShareTokenOrAuthMiddleware.
    'get_play_payload' => [
        'controller' => ['PlayController', 'getPlayPayload'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'get_shared_payload' => [
        'controller' => ['PlayController', 'getSharedPayload'],
        'middleware' => [LoggingMiddleware::class, [RateLimitMiddleware::class, ['limit' => 60, 'window' => 60, 'by' => 'ip']]],
    ],
    'save_print' => [
        'controller' => ['PlayController', 'savePrint'],
        'middleware' => [LoggingMiddleware::class, ShareTokenOrAuthMiddleware::class],
    ],
    'list_prints' => [
        'controller' => ['PlayController', 'listPrints'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],
    'get_print' => [
        'controller' => ['PlayController', 'getPrint'],
        'middleware' => [LoggingMiddleware::class, AuthenticationMiddleware::class],
    ],

    // ====================== TELE (PairingController) ======================
    // La APK no tiene cuenta: crea (10 cada 10 min por IP) y sondea con su deviceToken (40/min por
    // dispositivo = sha256 del token).
    // Reclamar exige cuenta y que el bingo sea suyo.
    'pair_create' => [
        'controller' => ['PairingController', 'create'],
        'middleware' => [LoggingMiddleware::class, [RateLimitMiddleware::class, ['limit' => 10, 'window' => 600, 'by' => 'ip']]],
    ],
    'pair_poll' => [
        'controller' => ['PairingController', 'poll'],
        'middleware' => [LoggingMiddleware::class, [RateLimitMiddleware::class, ['limit' => 40, 'window' => 60, 'by' => 'device']]],
    ],
    'pair_claim' => [
        'controller' => ['PairingController', 'claim'],
        'middleware' => [
            LoggingMiddleware::class,
            AuthenticationMiddleware::class,
            [RateLimitMiddleware::class, ['limit' => 10, 'window' => 60, 'by' => 'user']],
            CSRFMiddleware::class,
        ],
    ],
];
