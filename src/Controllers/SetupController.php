<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CsrfService;
use App\Services\InstallerService;
use App\Services\MailService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SetupController
{
    public function __construct(
        private readonly Twig $view,
        private readonly InstallerService $installer,
        private readonly MailService $mail,
        private readonly CsrfService $csrf,
        private readonly string $rootPath
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'setup/index.twig', [
            'installed' => $this->installer->isInstalled(),
            'defaults' => [
                'db_host' => 'localhost',
                'db_port' => '3306',
                'app_name' => 'Wahl-Sportkurse',
                'mail_from_name' => 'Wahl-Sportkurse',
                'mail_subject' => 'Ihre Sportkurs-Wahl',
            ],
        ]);
    }

    public function testDb(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        try {
            $this->installer->testConnection($this->dbFromRequest($data));
            return $this->json($response, ['ok' => true, 'message' => 'Verbindung erfolgreich.']);
        } catch (\Throwable $e) {
            return $this->json($response, ['ok' => false, 'message' => 'Verbindung fehlgeschlagen: ' . $e->getMessage()], 400);
        }
    }

    public function testMail(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $from = trim((string) ($data['mail_from'] ?? ''));
        $fromName = trim((string) ($data['mail_from_name'] ?? 'Wahl-Sportkurse'));
        $to = trim((string) ($data['mail_test_to'] ?? ''));

        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response, ['ok' => false, 'message' => 'Bitte gültige Absender-Adresse angeben.'], 400);
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response, ['ok' => false, 'message' => 'Bitte gültige Test-Empfängeradresse angeben.'], 400);
        }

        $sent = $this->mail->sendTest($to, $from, $fromName);
        if (!$sent) {
            return $this->json($response, [
                'ok' => false,
                'message' => 'mail() hat false zurückgegeben. Prüfen Sie die IONOS-Mail-Einstellungen.',
            ], 400);
        }
        return $this->json($response, ['ok' => true, 'message' => 'Testmail wurde versendet.']);
    }

    public function install(Request $request, Response $response): Response
    {
        if ($this->installer->isInstalled()) {
            return $this->view->render($response->withStatus(400), 'setup/index.twig', [
                'installed' => true,
                'error' => 'Die Anwendung ist bereits installiert.',
                'defaults' => [],
            ]);
        }

        $data = (array) $request->getParsedBody();
        $adminUser = trim((string) ($data['admin_username'] ?? ''));
        $adminPass = (string) ($data['admin_password'] ?? '');
        $adminPass2 = (string) ($data['admin_password_confirm'] ?? '');

        try {
            if ($adminUser === '' || strlen($adminPass) < 8) {
                throw new \RuntimeException('Admin-Benutzername und Passwort (mind. 8 Zeichen) sind erforderlich.');
            }
            if ($adminPass !== $adminPass2) {
                throw new \RuntimeException('Passwörter stimmen nicht überein.');
            }

            $mailFrom = trim((string) ($data['mail_from'] ?? ''));
            if ($mailFrom === '' || !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Bitte gültige Absender-E-Mail angeben.');
            }

            $appUrl = rtrim(trim((string) ($data['app_url'] ?? '')), '/');
            if ($appUrl === '') {
                throw new \RuntimeException('Bitte die öffentliche App-URL angeben (z. B. https://domain.de).');
            }

            $this->installer->install(
                $this->dbFromRequest($data),
                [
                    'from' => $mailFrom,
                    'from_name' => trim((string) ($data['mail_from_name'] ?? 'Wahl-Sportkurse')) ?: 'Wahl-Sportkurse',
                    'subject' => trim((string) ($data['mail_subject'] ?? 'Ihre Sportkurs-Wahl'))
                        ?: 'Ihre Sportkurs-Wahl',
                ],
                [
                    'username' => $adminUser,
                    'password' => $adminPass,
                ],
                [
                    'app_url' => $appUrl,
                    'app_name' => trim((string) ($data['app_name'] ?? 'Wahl-Sportkurse'))
                        ?: 'Wahl-Sportkurse',
                ]
            );

            return $this->view->render($response, 'setup/done.twig', [
                'admin_username' => $adminUser,
            ]);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(400), 'setup/index.twig', [
                'installed' => false,
                'error' => $e->getMessage(),
                'defaults' => $data,
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function dbFromRequest(array $data): array
    {
        return [
            'host' => trim((string) ($data['db_host'] ?? 'localhost')) ?: 'localhost',
            'port' => trim((string) ($data['db_port'] ?? '3306')) ?: '3306',
            'name' => trim((string) ($data['db_name'] ?? '')),
            'user' => trim((string) ($data['db_user'] ?? '')),
            'pass' => (string) ($data['db_pass'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }
}
