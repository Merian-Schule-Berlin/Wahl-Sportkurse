<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use App\Services\HalbjahrService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly HalbjahrService $halbjahre
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->auth->check()) {
            $response = new Response(302);
            return $response->withHeader('Location', '/administrator/login');
        }

        $path = $request->getUri()->getPath();
        $tab = (string) ($request->getQueryParams()['tab'] ?? '');

        $env = $this->view->getEnvironment();
        $env->addGlobal('admin_username', $this->auth->username());
        $env->addGlobal('admin_is_full', true);
        $env->addGlobal('admin_path', $path);
        $env->addGlobal('admin_tab', $tab);
        $env->addGlobal('admin_nav_halbjahre', $this->halbjahre->all());

        return $handler->handle($request);
    }
}
