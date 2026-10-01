<?php

declare(strict_types=1);

/**
 * Bootstrap de los scripts CLI de `backend/scripts/` (dentro del contenedor del backend):
 *
 *   docker compose exec -u www-data backend php scripts/<script>.php …                    (dev)
 *   docker compose -p bingo_prod -f docker-compose.prod.yml exec -u www-data backend php scripts/<script>.php …
 *
 * Autoloader + entorno de `bootstrap_min.php`, helpers y logging, y el MISMO contenedor DI de la API
 * (`config/container.php`): los scripts usan los servicios de verdad, no atajos. Sin sesión, sin
 * router y sin la cabecera JSON de `bootstrap.php`.
 *
 * Se niega a correr como root: los ficheros que escriba (fotos en `storage/uploads`, la caché
 * compilada del contenedor en prod) tienen que ser de www-data, o Apache no podrá borrarlos.
 *
 * @return \Psr\Container\ContainerInterface
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "No lo ejecutes como root: añade «-u www-data» al «docker compose exec».\n");
    exit(2);
}

require_once __DIR__ . '/../bootstrap_min.php';

foreach (['DB_HOST' => 'mysql', 'DB_PORT' => '3306'] as $key => $default) {
    $_ENV[$key] = $_ENV[$key] ?? (getenv($key) ?: $default);
}
foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'JWT_SECRET'] as $key) {
    if (!isset($_ENV[$key]) && getenv($key) !== false) {
        $_ENV[$key] = getenv($key);
    }
}

foreach (['/../config/helpers.php', '/../src/Infrastructure/Logging/functions.php', '/../config/logging.php'] as $file) {
    if (file_exists(__DIR__ . $file)) {
        require_once __DIR__ . $file;
    }
}

/** Opciones `--clave=valor` obligatorias; sale con 2 y el uso si falta alguna. */
function cli_options(array $required, string $usage): array
{
    $opts = getopt('', array_map(fn ($k) => $k . ':', $required));
    $missing = array_filter($required, fn ($k) => !isset($opts[$k]) || !is_string($opts[$k]) || $opts[$k] === '');
    if ($missing) {
        fwrite(STDERR, 'Falta: --' . implode(', --', $missing) . "\nUso: {$usage}\n");
        exit(2);
    }
    return $opts;
}

function cli_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit($code);
}

$containerFactory = require __DIR__ . '/../config/container.php';
return $containerFactory();
