<?php

declare(strict_types=1);

use App\Middleware\CsrfMiddleware;
use App\Middleware\InstalledMiddleware;
use App\Middleware\SessionMiddleware;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(require $root . '/config/container.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$basePath = $container->get('settings')['base_path'] ?? '';
if ($basePath !== '') {
    $app->setBasePath($basePath);
}

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));
$app->add(CsrfMiddleware::class);
$app->add(InstalledMiddleware::class);
$app->add(SessionMiddleware::class);

$errorMiddleware = $app->addErrorMiddleware(
    (bool) ($container->get('settings')['display_error_details'] ?? false),
    true,
    true
);

(require $root . '/config/routes.php')($app);

$app->run();
