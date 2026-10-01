<?php

declare(strict_types=1);

/**
 * Bajas (y altas) de cuentas: `users.is_active`.
 *
 * Uso (dentro del contenedor del backend):
 *   docker compose exec -u www-data backend php scripts/user_set_active.php --email=alguien@gmail.com --active=0
 *   docker compose -p bingo_prod -f docker-compose.prod.yml exec -u www-data backend \
 *       php scripts/user_set_active.php --email=alguien@gmail.com --active=1
 *
 * Con `--active=0` la cuenta queda cortada al momento: AuthenticationMiddleware mira la fila en cada
 * petición (403 a sesión y JWT ya emitidos), `login` responde 403 y sus enlaces compartidos dejan de
 * funcionar (`findShared` exige `is_active = 1`). No borra nada: `--active=1` la devuelve tal cual.
 *
 * Salida: 0 hecho · 1 no existe la cuenta · 2 uso incorrecto.
 */

use App\Domain\Model\ValueObjects\Email;
use App\Domain\Repository\User\UserRepositoryInterface;

$container = require __DIR__ . '/bootstrap_cli.php';

$usage = 'php scripts/user_set_active.php --email=<email> --active=0|1';
$opts = cli_options(['email', 'active'], $usage);
if (!in_array($opts['active'], ['0', '1'], true)) {
    fwrite(STDERR, "--active tiene que ser 0 o 1\nUso: {$usage}\n");
    exit(2);
}
$active = $opts['active'] === '1';

try {
    $email = Email::fromString(trim($opts['email']));
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Email no válido: {$opts['email']}\n");
    exit(2);
}

/** @var UserRepositoryInterface $users */
$users = $container->get(UserRepositoryInterface::class);
$user = $users->findByEmail($email);
if ($user === null) {
    cli_fail("No hay ninguna cuenta con el email {$email->toString()}.");
}

$before = $user->isActive();
if ($before !== $active) {
    $user->setActive($active);
    $users->update($user);
}
$user = $users->findById((int) $user->getId());

printf(
    "Cuenta #%d <%s>: is_active %d → %d%s\n",
    $user->getId(),
    $user->getEmail()->toString(),
    (int) $before,
    (int) $user->isActive(),
    $before === $active ? ' (sin cambios)' : ''
);
exit($user->isActive() === $active ? 0 : 1);
