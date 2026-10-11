<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\InstallerService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class InstalledMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly InstallerService $installer)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $isSetup = str_starts_with($path, '/setup');

        if (!$this->installer->isInstalled() && !$isSetup) {
            $response = new Response(302);
            return $response->withHeader('Location', '/setup');
        }

        return $handler->handle($request);
    }
}
