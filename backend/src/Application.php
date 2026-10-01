<?php
declare(strict_types=1);

namespace App;

use App\Router\ActionRouter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Main Application Class
 * 
 * Refactored to use PHP-DI for dependency injection.
 * All dependencies are now managed by the DI container defined in config/container.php.
 * 
 * Benefits of this approach:
 * - Autowiring: Dependencies are resolved automatically
 * - Cleaner code: No manual instantiation of dozens of classes
 * - Better testability: Easy to swap implementations
 * - Single Responsibility: Application focuses on request/response handling
 */
class Application
{
    private float $startTime;
    private string $requestMethod;
    private string $requestUri;
    private ContainerInterface $container;
    private ActionRouter $router;
    
    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $this->requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        
        $this->bootstrap();
        $this->initializeContainer();
    }
    
    /**
     * Bootstrap the application
     * Handles CORS, session, and initial logging
     */
    private function bootstrap(): void
    {
        // Handle OPTIONS requests immediately for CORS preflight
        if ($this->requestMethod === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
        
        // Start session ONCE at application bootstrap before any middleware
        // This prevents multiple session_start() calls from creating different sessions
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            // Configure session settings for security
            ini_set('session.cookie_httponly', '1');
            // Don't require HTTPS in development
            $isProduction = ($_ENV['APP_ENV'] ?? 'development') === 'production';
            ini_set('session.cookie_secure', $isProduction ? '1' : '0');
            // Use Lax samesite for development (allows some cross-origin)
            ini_set('session.cookie_samesite', $isProduction ? 'Strict' : 'Lax');
            // Force cookie domain to be empty for localhost
            ini_set('session.cookie_domain', '');
            // Set cookie path to root
            ini_set('session.cookie_path', '/');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.gc_maxlifetime', '604800'); // 7 days
            
            session_name('BINGO_SESSION');
            session_start();
        }
        
        // Set response headers
        header('Content-Type: application/json');
        
        // Initialize logging for the request
        if (function_exists('logger')) {
            try {
                logger('api')->httpRequest($this->requestMethod, $this->requestUri, [
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null
                ]);
            } catch (Throwable $e) {
                error_log("Logging error on request start: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Initialize the DI container
     * All dependencies are configured in config/container.php
     */
    private function initializeContainer(): void
    {
        // Load the DI container configuration
        $containerFactory = require __DIR__ . '/../config/container.php';
        $this->container = $containerFactory();
        
        // Get the router from container (it's already configured with routes and dependencies)
        $this->router = $this->container->get(ActionRouter::class);
    }
    
    public function run(): void
    {
        $response = [
            'status' => 'error',
            'message' => 'An unexpected error occurred.',
            'data' => null
        ];
        $statusCode = 500;
        
        try {
            // Body más grande que post_max_size: PHP lo descarta entero ($_POST y $_FILES vacíos),
            // así que ni siquiera hay `action`. Solo puede ser una subida demasiado grande.
            if ($this->isOversizedPost()) {
                $this->sendResponse(['status' => 'error', 'message' => 'La foto pesa más de 10 MB.', 'data' => null], 413);
                return;
            }

            $inputData = $this->readInput();

            // Determine action
            $action = $inputData['action'] ?? $_REQUEST['action'] ?? null;

            $result = $this->router->dispatch($action, $inputData);
            $response = $result;
            $statusCode = $result['http_code'] ?? ($result['status'] === 'success' ? 200 : 400);
            
        } catch (InvalidArgumentException $e) {
            $response['status'] = 'error';
            $response['message'] = $e->getMessage();
            $statusCode = 400;
            
            $this->logError('validation', $e, $action ?? 'unknown');
            
        } catch (RuntimeException $e) {
            $this->logError('runtime', $e, $action ?? 'unknown');
            
            $response['status'] = 'error';
            $response['message'] = 'A server runtime error occurred. Please try again later.';
            $statusCode = 500;
            
        } catch (Throwable $e) {
            $this->logException($e);
            
            $response['status'] = 'error';
            $response['message'] = 'An unexpected server error occurred.';
            $statusCode = 500;
        }
        
        $this->sendResponse($response, $statusCode);
    }
    
    /**
     * Datos de la petición: JSON en el body (todas las acciones) o, para `multipart/form-data`
     * (`upload_image`), los campos de `$_POST`; el fichero lo lee el controlador de `$_FILES['file']`.
     */
    private function readInput(): array
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_starts_with($contentType, 'multipart/form-data')) {
            return $_POST;
        }
        $decoded = json_decode(file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isOversizedPost(): bool
    {
        if ($this->requestMethod !== 'POST' || !empty($_POST) || !empty($_FILES)) {
            return false;
        }
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $limit = $this->iniBytes((string) ini_get('post_max_size'));
        return $limit > 0 && $length > $limit;
    }

    /** «12M» → 12582912. */
    private function iniBytes(string $value): int
    {
        $value = trim($value);
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private function logError(string $type, Throwable $e, string $action): void
    {
        if (!function_exists('logger')) {
            // Fallback to error_log if logger is not available
            error_log("[$type] Error in action '$action': " . $e->getMessage());
            return;
        }
        
        try {
            $context = [
                'message' => $e->getMessage(),
                'action' => $action,
                'method' => $this->requestMethod,
                'uri' => $this->requestUri,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'exception_class' => get_class($e)
            ];
            
            if ($type === 'runtime') {
                $context['stack_trace'] = $e->getTraceAsString();
                logger('api')->error('Runtime Error', $context);
            } else {
                logger('api')->warning('Validation Error', $context);
            }
        } catch (Throwable $logError) {
            error_log("Logging error in {$type}: " . $logError->getMessage());
        }
    }
    
    private function logException(Throwable $e): void
    {
        if (function_exists('logger')) {
            try {
                $context = [
                    'exception_class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'stack_trace' => $e->getTraceAsString(),
                    'action' => $_REQUEST['action'] ?? 'unknown',
                    'method' => $this->requestMethod,
                    'uri' => $this->requestUri
                ];
                logger('api')->critical('Unexpected Exception', $context);
            } catch (Throwable $logError) {
                error_log("Logging error in exception: " . $logError->getMessage());
            }
        } else {
            // Fallback to error_log if logger is not available
            error_log("Critical Exception: " . get_class($e) . " in " . $e->getFile() . ":" . $e->getLine() . " - " . $e->getMessage());
        }
    }
    
    private function sendResponse(array $response, int $statusCode): void
    {
        // Log response
        $duration = microtime(true) - $this->startTime;
        if (function_exists('logger')) {
            try {
                logger('api')->httpResponse($statusCode, $duration, [
                    'action' => $_REQUEST['action'] ?? 'unknown',
                    'response_size' => strlen(json_encode($response))
                ]);
            } catch (Throwable $logError) {
                error_log("Logging error on response: " . $logError->getMessage());
            }
        }
        
        http_response_code($statusCode);
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Get a service from the DI container
     * Useful for accessing services from outside the Application class
     */
    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }
}
