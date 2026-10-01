<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use App\Infrastructure\Database\DatabaseConnector;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Session\SessionManager;

return function (): ContainerInterface {
    $containerBuilder = new ContainerBuilder();

    if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
        $containerBuilder->enableCompilation(__DIR__ . '/../var/cache');
    }

    $containerBuilder->addDefinitions([

        // ===========================
        // INFRASTRUCTURE
        // ===========================
        PDO::class => function () {
            return (new DatabaseConnector())->getConnection();
        },
        'db' => DI\get(PDO::class),

        LoggerInterface::class => function () {
            return LoggerFactory::createDatabaseLogger();
        },

        SessionManager::class => DI\autowire(),
        \App\Infrastructure\Auth\JWTService::class => DI\autowire(),
        \App\Infrastructure\Auth\GoogleOAuthVerifier::class => DI\autowire(),
        \App\Infrastructure\Auth\SignedUrlService::class => DI\autowire(),
        // oEmbed de YouTube (canciones). Sin cliente inyectado crea su Guzzle; los tests le pasan uno mock.
        \App\Infrastructure\Youtube\OEmbedClient::class => DI\autowire()->constructorParameter('http', null),

        // ===========================
        // MIDDLEWARE
        // ===========================
        \App\Infrastructure\Middleware\AuthMiddleware::class => DI\autowire(),
        \App\Infrastructure\Middleware\AuthenticationMiddleware::class => DI\autowire(),
        \App\Infrastructure\Middleware\LoggingMiddleware::class => DI\autowire(),
        \App\Infrastructure\Middleware\CSRFMiddleware::class => DI\autowire(),
        \App\Infrastructure\Middleware\ShareTokenOrAuthMiddleware::class => DI\autowire(),
        // Rate limits (M6). Configurado por ruta (routes.php) con setConfig; una acción por petición.
        \App\Infrastructure\Middleware\RateLimitMiddleware::class => DI\autowire(),

        // ===========================
        // DATA MAPPERS
        // ===========================
        \App\Infrastructure\Persistence\User\Mappers\UserDataMapper::class => DI\autowire(),

        // ===========================
        // REPOSITORIES (interface -> implementation)
        // ===========================
        \App\Domain\Repository\User\UserRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\User\MySqlUserRepository::class),

        \App\Infrastructure\Persistence\User\MySqlUserRepository::class => DI\autowire(),

        \App\Domain\Repository\Bingo\BingoRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Bingo\MySqlBingoRepository::class),
        \App\Infrastructure\Persistence\Bingo\MySqlBingoRepository::class => DI\autowire(),

        \App\Domain\Repository\Upload\UploadRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Upload\MySqlUploadRepository::class),
        \App\Infrastructure\Persistence\Upload\MySqlUploadRepository::class => DI\autowire(),

        \App\Domain\Repository\Print\PrintRepositoryInterface::class
            => DI\get(\App\Infrastructure\Persistence\Print\MySqlPrintRepository::class),
        \App\Infrastructure\Persistence\Print\MySqlPrintRepository::class => DI\autowire(),

        // ===========================
        // DOMAIN SERVICES
        // ===========================
        \App\Domain\Services\EmailWhitelist::class => DI\autowire(),
        // Fotos (M3). PDO es una sola conexión por petición (PHP-DI cachea la entrada): los
        // repositorios y QuotaService comparten la transacción que abre el controlador.
        \App\Domain\Services\BingoValidator::class => DI\autowire(),
        \App\Domain\Services\BingoPresenter::class => DI\autowire(),
        \App\Domain\Services\ImageProcessor::class => DI\autowire(),
        \App\Domain\Services\QuotaService::class => DI\autowire(),
        \App\Domain\Services\UploadStorage::class => DI\autowire()->constructorParameter('root', null),
        // Camino único de una foto (upload_image y el import de la fiesta de casa, M6).
        \App\Domain\Services\ImageUploadService::class => DI\autowire(),
        // Jugar e imprimir (M4).
        \App\Domain\Services\PlayPresenter::class => DI\autowire(),
        // Tele por código (M5).
        \App\Domain\Services\PairingService::class => DI\autowire(),
        // Canciones de YouTube.
        \App\Domain\Services\YoutubeUrlParser::class => DI\autowire(),

        // ===========================
        // USE CASES - Auth
        // ===========================
        \App\Domain\UseCases\Auth\LoginUserUseCase::class => DI\autowire(),

        // ===========================
        // CONTROLLERS
        // ===========================
        \App\Controllers\AuthController::class => DI\autowire(),
        \App\Controllers\BingoController::class => DI\autowire(),
        \App\Controllers\UploadController::class => DI\autowire(),
        \App\Controllers\PlayController::class => DI\autowire(),
        \App\Controllers\PairingController::class => DI\autowire(),

        // ===========================
        // ROUTER
        // ===========================
        \App\Router\ActionRouter::class => DI\autowire()
            ->constructorParameter('routes', require __DIR__ . '/routes.php')
            ->constructorParameter('container', DI\get(ContainerInterface::class))
            ->constructorParameter('logger', DI\get(LoggerInterface::class)),
    ]);

    return $containerBuilder->build();
};
