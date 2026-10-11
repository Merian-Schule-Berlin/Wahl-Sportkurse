<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuthService;
use App\Services\LoginThrottleService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly LoginThrottleService $throttle
    ) {
    }

    public function loginForm(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $response->withHeader('Location', '/administrator')->withStatus(302);
        }
        return $this->renderLogin($response, $this->clientIp($request));
    }

    public function loginSubmit(Request $request, Response $response): Response
    {
        $ip = $this->clientIp($request);
        $lockSeconds = $this->throttle->remainingLockSeconds($ip);
        if ($lockSeconds > 0) {
            return $this->renderLogin(
                $response->withStatus(429),
                $ip,
                $this->lockMessage($lockSeconds)
            );
        }

        $data = (array) $request->getParsedBody();
        $ok = $this->auth->attempt(
            trim((string) ($data['username'] ?? '')),
            (string) ($data['password'] ?? '')
        );
        if (!$ok) {
            $this->throttle->recordFailure($ip);
            $lockSeconds = $this->throttle->remainingLockSeconds($ip);
            $error = $lockSeconds > 0
                ? $this->lockMessage($lockSeconds)
                : 'Benutzername oder Passwort ungültig.';
            return $this->renderLogin($response->withStatus(401), $ip, $error);
        }

        $this->throttle->clear($ip);
        return $response->withHeader('Location', '/administrator')->withStatus(302);
    }

    private function renderLogin(Response $response, string $ip, ?string $error = null): Response
    {
        $lockSeconds = $this->throttle->remainingLockSeconds($ip);
        return $this->view->render($response, 'admin/login.twig', [
            'error' => $error,
            'locked' => $lockSeconds > 0,
            'lock_seconds' => $lockSeconds,
        ]);
    }

    private function lockMessage(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        return 'Zu viele fehlgeschlagene Anmeldeversuche. Bitte in ca. '
            . $minutes . ' Minute' . ($minutes === 1 ? '' : 'n') . ' erneut versuchen.';
    }

    private function clientIp(Request $request): string
    {
        $server = $request->getServerParams();
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? $ip : 'unknown';
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();
        return $response->withHeader('Location', '/administrator/login')->withStatus(302);
    }
}
