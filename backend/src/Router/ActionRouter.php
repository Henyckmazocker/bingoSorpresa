<?php

declare(strict_types=1);

namespace App\Router;

use App\Controllers\AuthController;
use App\Controllers\BingoController;
use App\Controllers\PairingController;
use App\Controllers\PlayController;
use App\Controllers\UploadController;
use App\Infrastructure\Middleware\MiddlewarePipeline;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ActionRouter - Routes incoming actions to the appropriate controller.
 *
 * Uses the Middleware Pattern (config/routes.php declares the middleware stack
 * per action) so auth/CSRF/validation/logging concerns stay out of controllers.
 */
class ActionRouter
{
    private array $routes;
    private ContainerInterface $container;
    private LoggerInterface $logger;

    public function __construct(
        array $routes,
        ContainerInterface $container,
        LoggerInterface $logger
    ) {
        $this->routes = $routes;
        $this->container = $container;
        $this->logger = $logger;
    }

    public function dispatch(?string $action, array $inputData): array
    {
        try {
            if ($action === null || !isset($this->routes[$action])) {
                return $this->handleUnknownAction($action);
            }

            $route = $this->routes[$action];

            $request = [
                'action' => $action,
                'data' => $inputData,
                'csrf_token' => $inputData['csrf_token'] ?? null,
            ];

            $pipeline = new MiddlewarePipeline();
            foreach ($route['middleware'] as $middlewareConfig) {
                if (is_array($middlewareConfig)) {
                    [$middlewareClass, $config] = $middlewareConfig;
                    $middleware = $this->container->get($middlewareClass);
                    if (method_exists($middleware, 'setConfig')) {
                        $middleware->setConfig($config);
                    }
                    $pipeline->add($middleware);
                } else {
                    $pipeline->add($this->container->get($middlewareConfig));
                }
            }

            return $pipeline->execute($request, function (array $request) use ($route) {
                return $this->executeController($route['controller'], $request);
            });
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('ActionRouter Validation Error', [
                'message' => $e->getMessage(),
                'action' => $action,
            ]);
            return ['status' => 'error', 'message' => $e->getMessage(), 'http_code' => 400];
        } catch (\Throwable $e) {
            $this->logger->error('ActionRouter Unexpected Error', [
                'message' => $e->getMessage(),
                'action' => $action,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['status' => 'error', 'message' => 'An unexpected error occurred.', 'http_code' => 500];
        }
    }

    private function executeController(array $controllerConfig, array $request): array
    {
        [$controllerName, $method] = $controllerConfig;

        // Health check must not depend on any controller (or its config).
        if ($request['action'] === 'ping') {
            return ['status' => 'success', 'message' => 'pong', 'http_code' => 200];
        }

        $controller = $this->getController($controllerName);
        $data = $request['data'];
        $userId = $request['user_id'] ?? null;

        return match ($request['action']) {
            // ---- AUTH ----
            'login' => $controller->login($data),
            'logout' => $controller->logout(),
            'check_auth' => $controller->checkAuth(),
            'me' => $controller->me($userId),
            'delete_account' => $controller->deleteAccount($userId, $data),
            'ping' => ['status' => 'success', 'message' => 'pong', 'http_code' => 200],

            // ---- BINGOS ----
            'list_bingos' => $controller->listBingos($userId),
            'get_bingo' => $controller->getBingo($userId, $data),
            'create_bingo' => $controller->createBingo($userId, $data),
            'update_bingo' => $controller->updateBingo($userId, $data),
            'delete_bingo' => $controller->deleteBingo($userId, $data),
            'duplicate_bingo' => $controller->duplicateBingo($userId, $data),
            'set_share' => $controller->setShare($userId, $data),
            'update_item' => $controller->updateItem($userId, $data),
            'delete_item' => $controller->deleteItem($userId, $data),
            'add_music_items' => $controller->addMusicItems($userId, $data),

            // ---- SUBIDA (multipart: el fichero llega por $_FILES, no por el body) ----
            'upload_image' => $controller->uploadImage($userId, $data, $_FILES['file'] ?? null),

            // ---- JUGAR E IMPRIMIR ----
            'get_play_payload' => $controller->getPlayPayload($userId, $data),
            'get_shared_payload' => $controller->getSharedPayload($data),
            'save_print' => $controller->savePrint($userId, $data, !empty($request['share_access'])),
            'list_prints' => $controller->listPrints($userId, $data),
            'get_print' => $controller->getPrint($userId, $data),

            // ---- TELE ----
            'pair_create' => $controller->create(),
            'pair_poll' => $controller->poll($data),
            'pair_claim' => $controller->claim($userId, $data),

            default => [
                'status' => 'error',
                'message' => "Controller method not mapped for action: {$request['action']}",
                'http_code' => 500,
            ],
        };
    }

    private function getController(string $controllerName): object
    {
        return match ($controllerName) {
            'AuthController' => $this->container->get(AuthController::class),
            'BingoController' => $this->container->get(BingoController::class),
            'UploadController' => $this->container->get(UploadController::class),
            'PlayController' => $this->container->get(PlayController::class),
            'PairingController' => $this->container->get(PairingController::class),
            default => throw new \RuntimeException("Unknown controller: {$controllerName}"),
        };
    }

    private function handleUnknownAction(?string $action): array
    {
        $this->logger->warning('Unknown action requested', [
            'action' => $action,
            'available_routes' => array_keys($this->routes),
        ]);

        return [
            'status' => 'error',
            'message' => 'No valid action specified. Action: ' . ($action ?? 'null'),
            'http_code' => 400,
        ];
    }
}
