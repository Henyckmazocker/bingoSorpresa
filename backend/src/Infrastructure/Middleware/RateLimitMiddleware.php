<?php

declare(strict_types=1);

namespace App\Infrastructure\Middleware;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * Rate limit por ventana fija sobre la tabla `rate_limits` (bucket, window_start, hits).
 *
 * Se declara por ruta en `config/routes.php`, con su configuración:
 *   [RateLimitMiddleware::class, ['limit' => 10, 'window' => 60, 'by' => 'ip']]
 *
 * - `by` = 'ip'     → la IP del cliente: `HTTP_X_REAL_IP` (la pone nginx; en prod, ya resuelta por
 *                     el `real_ip` de Cloudflare) o, si falta o no es una IP, `REMOTE_ADDR`.
 *          'user'   → el `user_id` que inyecta AuthenticationMiddleware (va DESPUÉS de él en la pila;
 *                     sin él, cae a la IP).
 *          'device' → sha256 del `deviceToken` del body (nunca el token en claro; sin él, la IP).
 * - Bucket: '<acción>:<ip|user_id|device>'. Ventana: `floor(now / window) * window`.
 * - Cada petición suma un hit (también las que acaban fallando: el login cuenta antes de verificar
 *   el token de Google). `hits` es SMALLINT UNSIGNED: se satura en 65535, no desborda.
 * - Pasado el límite: 429, cabecera `Retry-After` = segundos hasta el fin de la ventana, y
 *   `data.retryAfter` con el mismo valor.
 * - Limpieza: una de cada 100 peticiones borra las ventanas de hace más de 2 h (la mayor es 1 h).
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public const HITS_MAX = 65535;
    public const PURGE_AGE = 7200;
    public const PURGE_ONE_IN = 100;

    private int $limit = 0;
    private int $window = 60;
    private string $by = 'ip';

    /** @var callable(): int */
    private $clock;
    /** @var callable(): bool */
    private $purgeDice;

    /**
     * @param callable|null $clock     segundos Unix «ahora» (inyectable en los tests)
     * @param callable|null $purgeDice true cuando toca limpiar (inyectable en los tests)
     */
    public function __construct(
        private readonly PDO $db,
        private readonly LoggerInterface $logger,
        ?callable $clock = null,
        ?callable $purgeDice = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->purgeDice = $purgeDice ?? static fn (): bool => random_int(1, self::PURGE_ONE_IN) === 1;
    }

    /** @param array{limit:int, window:int, by?:string} $config */
    public function setConfig(array $config): void
    {
        $limit = (int) ($config['limit'] ?? 0);
        $window = (int) ($config['window'] ?? 0);
        $by = $config['by'] ?? 'ip';
        if ($limit < 1 || $limit >= self::HITS_MAX || $window < 1 || !in_array($by, ['ip', 'user', 'device'], true)) {
            throw new \LogicException('RateLimitMiddleware: configuración inválida en routes.php');
        }
        $this->limit = $limit;
        $this->window = $window;
        $this->by = $by;
    }

    public function handle(array $request, callable $next): array
    {
        if ($this->limit < 1) {
            throw new \LogicException('RateLimitMiddleware sin configurar: decláralo con [clase, config].');
        }
        $action = (string) ($request['action'] ?? 'unknown');
        $now = ($this->clock)();
        $windowStart = intdiv($now, $this->window) * $this->window;
        $bucket = $action . ':' . $this->subject($request);

        if (($this->purgeDice)()) {
            $this->purge($now);
        }

        $hits = $this->hit($bucket, $windowStart);
        if ($hits <= $this->limit) {
            return $next($request);
        }

        $retryAfter = max(1, $windowStart + $this->window - $now);
        $this->logger->warning('Rate limit exceeded', [
            'action' => $action,
            'by' => $this->by,
            'hits' => $hits,
            'limit' => $this->limit,
            'window' => $this->window,
        ]);
        if (!headers_sent()) {
            header('Retry-After: ' . $retryAfter);
        }
        return [
            'status' => 'error',
            'message' => "Demasiadas peticiones. Prueba de nuevo en {$retryAfter} s.",
            'data' => ['retryAfter' => $retryAfter],
            'http_code' => 429,
        ];
    }

    /** Suma un hit (saturando en 65535) y devuelve los de esta ventana. */
    private function hit(string $bucket, int $windowStart): int
    {
        $bump = 'CASE WHEN hits < ' . self::HITS_MAX . ' THEN hits + 1 ELSE hits END';
        $sql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "INSERT INTO rate_limits (bucket, window_start, hits) VALUES (:b, :w, 1)
               ON CONFLICT (bucket, window_start) DO UPDATE SET hits = {$bump}"
            : "INSERT INTO rate_limits (bucket, window_start, hits) VALUES (:b, :w, 1)
               ON DUPLICATE KEY UPDATE hits = {$bump}";
        $this->db->prepare($sql)->execute(['b' => $bucket, 'w' => $windowStart]);

        $stmt = $this->db->prepare('SELECT hits FROM rate_limits WHERE bucket = :b AND window_start = :w');
        $stmt->execute(['b' => $bucket, 'w' => $windowStart]);
        return (int) $stmt->fetchColumn();
    }

    private function purge(int $now): void
    {
        $stmt = $this->db->prepare('DELETE FROM rate_limits WHERE window_start < :t');
        $stmt->execute(['t' => $now - self::PURGE_AGE]);
    }

    private function subject(array $request): string
    {
        if ($this->by === 'user' && isset($request['user_id'])) {
            return (string) (int) $request['user_id'];
        }
        if ($this->by === 'device') {
            $token = $request['data']['deviceToken'] ?? null;
            if (is_string($token) && $token !== '') {
                return hash('sha256', $token);
            }
        }
        return self::clientIp();
    }

    /** IP del cliente: X-Real-IP (nginx) si es una IP válida; si no, REMOTE_ADDR. */
    public static function clientIp(): string
    {
        $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
