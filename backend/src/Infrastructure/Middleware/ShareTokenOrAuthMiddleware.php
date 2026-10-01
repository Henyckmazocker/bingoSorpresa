<?php

declare(strict_types=1);

namespace App\Infrastructure\Middleware;

/**
 * Para acciones que valen con cuenta O con un enlace compartido (`save_print {bingo_id|share_token}`).
 *
 * - Con `share_token` en el body: acceso público, sin sesión ni CSRF (en `/b/:token` desde una
 *   ventana privada no hay ni cookie ni token CSRF). El controlador resuelve el token y responde
 *   404 si no vale; `user_id` NO se inyecta, así que nunca se mezcla con un acceso de dueño.
 * - Sin él: exactamente la pila de una escritura con cuenta (Authentication + CSRF).
 */
class ShareTokenOrAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthenticationMiddleware $authentication,
        private readonly CSRFMiddleware $csrf
    ) {}

    public function handle(array $request, callable $next): array
    {
        $token = $request['data']['share_token'] ?? null;
        if (is_string($token) && $token !== '') {
            $request['share_access'] = true;
            return $next($request);
        }
        return $this->authentication->handle(
            $request,
            fn (array $req) => $this->csrf->handle($req, $next)
        );
    }
}
