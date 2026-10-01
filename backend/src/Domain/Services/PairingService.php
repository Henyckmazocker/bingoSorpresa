<?php

declare(strict_types=1);

namespace App\Domain\Services;

use PDO;
use PDOException;

/**
 * Emparejado de la tele por código (`tv_pairings`).
 *
 * 1. La tele (APK, sin cuenta) pide `create()`: un código de 6 cifras, que enseña en grande y en un
 *    QR, y un `deviceToken` secreto que solo ella conoce. De ese token solo se guarda el sha256.
 * 2. Con el móvil logueado, el dueño de un bingo hace `claim(code, bingoId)`.
 * 3. La tele sondea `poll(deviceToken)` hasta ver el bingo reclamado.
 *
 * Caducidad: `expires_at` = creación + 10 min. Pasado ese momento el código no se puede reclamar
 * y el sondeo da «caducado» (410: la tele pide otro). Los caducados se borran en cada `create()`:
 * `code` es UNIQUE y, si se quedaran, `random_int` acabaría chocando con códigos muertos.
 *
 * Un pairing reclamado NO se borra al entregar el payload: sigue respondiendo `ready` hasta que
 * caduca (lo barre el siguiente `create()`). Así, si la respuesta del sondeo se pierde por la red,
 * el siguiente sondeo vuelve a darla. Para que un código reclamado en el último segundo no caduque
 * antes de que la tele sondee (cada 3 s), reclamar le asegura al menos `CLAIM_GRACE` segundos más.
 *
 * Las fechas se escriben y comparan desde PHP en UTC (`gmdate`), nunca con NOW() de la BD: así el
 * mismo SQL vale en MySQL y en SQLite (tests) y no depende de la zona horaria del servidor.
 */
class PairingService
{
    public const TTL = 600;
    public const CLAIM_GRACE = 60;
    public const CODE_ATTEMPTS = 10;

    public const CLAIM_OK = 'ok';
    public const CLAIM_NOT_FOUND = 'not_found';
    public const CLAIM_TAKEN = 'taken';

    /** @var callable(): int */
    private $clock;

    /** @param callable|null $clock segundos Unix «ahora» (inyectable en los tests) */
    public function __construct(private readonly PDO $db, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array{code:string, deviceToken:string, expiresIn:int} */
    public function create(): array
    {
        $now = $this->now();
        $this->purgeExpired($now);

        $deviceToken = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO tv_pairings (code, device_token_hash, expires_at) VALUES (:c, :h, :e)'
        );
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $code = self::generateCode();
            try {
                $stmt->execute([
                    'c' => $code,
                    'h' => self::hash($deviceToken),
                    'e' => self::datetime($now + self::TTL),
                ]);
                return ['code' => $code, 'deviceToken' => $deviceToken, 'expiresIn' => self::TTL];
            } catch (PDOException $e) {
                // 23000 = violación de integridad: el código choca con uno vivo. Otro número.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('No se pudo generar un código de emparejado libre.');
    }

    /**
     * Estado del pairing de este token: null si no existe o ha caducado (→ 410); si no,
     * `['bingoId' => ?int, 'ownerId' => ?int]` (bingoId null = aún esperando).
     */
    public function poll(string $deviceToken): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT bingo_id, claimed_by FROM tv_pairings WHERE device_token_hash = :h AND expires_at > :now'
        );
        $stmt->execute(['h' => self::hash($deviceToken), 'now' => self::datetime($this->now())]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'bingoId' => $row['bingo_id'] !== null ? (int) $row['bingo_id'] : null,
            'ownerId' => $row['claimed_by'] !== null ? (int) $row['claimed_by'] : null,
        ];
    }

    /**
     * Asigna el bingo al código. La propiedad del bingo la comprueba el llamador.
     * @return string CLAIM_OK | CLAIM_NOT_FOUND (no existe o caducó) | CLAIM_TAKEN (ya reclamado)
     */
    public function claim(string $code, int $bingoId, int $userId): string
    {
        $now = $this->now();
        $stmt = $this->db->prepare(
            'SELECT id, bingo_id, expires_at FROM tv_pairings WHERE code = :c AND expires_at > :now'
        );
        $stmt->execute(['c' => $code, 'now' => self::datetime($now)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return self::CLAIM_NOT_FOUND;
        }
        if ($row['bingo_id'] !== null) {
            return self::CLAIM_TAKEN;
        }
        $expires = max(strtotime($row['expires_at'] . ' UTC'), $now + self::CLAIM_GRACE);
        // `bingo_id IS NULL` en el WHERE: de dos reclamaciones simultáneas solo gana una.
        $update = $this->db->prepare(
            'UPDATE tv_pairings SET bingo_id = :b, claimed_by = :u, expires_at = :e
              WHERE id = :id AND bingo_id IS NULL'
        );
        $update->execute([
            'b' => $bingoId,
            'u' => $userId,
            'e' => self::datetime($expires),
            'id' => (int) $row['id'],
        ]);
        return $update->rowCount() === 1 ? self::CLAIM_OK : self::CLAIM_TAKEN;
    }

    /** ¿Tiene forma de código (6 cifras)? */
    public static function isCode(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{6}$/D', $value) === 1;
    }

    /** ¿Tiene forma de deviceToken (64 hex)? */
    public static function isDeviceToken(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    public static function hash(string $deviceToken): string
    {
        return hash('sha256', $deviceToken);
    }

    private function purgeExpired(int $now): void
    {
        $stmt = $this->db->prepare('DELETE FROM tv_pairings WHERE expires_at <= :now');
        $stmt->execute(['now' => self::datetime($now)]);
    }

    private static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function datetime(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
