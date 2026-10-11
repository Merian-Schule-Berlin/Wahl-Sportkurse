<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CsrfService $csrf)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $body = $request->getParsedBody();
            $token = is_array($body) ? ($body['_csrf'] ?? null) : null;
            if (!$this->csrf->validate(is_string($token) ? $token : null)) {
                $response = new Response(403);
                $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
                if ($isAjax) {
                    $response->getBody()->write(json_encode([
                        'ok' => false,
                        'error' => 'Ungültiges CSRF-Token. Bitte Seite neu laden.',
                    ], JSON_THROW_ON_ERROR));
                    return $response->withHeader('Content-Type', 'application/json');
                }
                $response->getBody()->write('Ungültiges CSRF-Token. Bitte Seite neu laden.');
                return $response;
            }
        }
        return $handler->handle($request);
    }
}
