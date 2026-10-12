<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnmeldungService;
use App\Services\AuthService;
use App\Services\BotProtectionService;
use App\Services\CsrfService;
use App\Services\HalbjahrService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PublicController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SettingsService $settings,
        private readonly HalbjahrService $halbjahre,
        private readonly AnmeldungService $anmeldungen,
        private readonly CsrfService $csrf,
        private readonly AuthService $auth,
        private readonly BotProtectionService $botProtection,
        private readonly string $uploadDir
    ) {
    }

    public function home(Request $request, Response $response): Response
    {
        $all = $this->settings->all();
        $success = $_SESSION['flash_success'] ?? null;
        $successToken = $_SESSION['flash_success_token'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_success_token']);

        return $this->view->render($response, 'public/home.twig', [
            'welcome_text' => $all['welcome_text'] ?? '',
            'welcome_image' => $all['welcome_image'] ?? '',
            'open' => $this->settings->isRegistrationOpen(),
            'registration_start' => $all['registration_start'] ?? '',
            'registration_end' => $all['registration_end'] ?? '',
            'admin_logged_in' => $this->auth->check(),
            'success' => $success,
            'success_token' => $successToken,
        ]);
    }

    public function registerForm(Request $request, Response $response): Response
    {
        $gate = $this->registrationGate($response);
        if ($gate !== null) {
            return $gate;
        }

        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $matrix = $this->halbjahre->registrationMatrix();
        return $this->view->render($response, 'public/register.twig', [
            'halbjahre' => $matrix['halbjahre'],
            'kurse' => $matrix['kurse'],
            'form_ready' => $this->isFormReady($matrix['halbjahre'], $matrix['kurse']),
            'error' => $flash,
            'old' => [],
            'admin_test_mode' => !$open && $adminTest,
            'kursbeschreibungen' => $this->kursbeschreibungenUrl(),
            'kursbeschreibungen_html' => $this->kursbeschreibungenHtml(),
            'bot_protection' => $this->botProtection->widgetConfig(),
        ]);
    }

    public function registerSubmit(Request $request, Response $response): Response
    {
        $gate = $this->registrationGate($response);
        if ($gate !== null) {
            return $gate;
        }

        $data = (array) $request->getParsedBody();
        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        $matrix = $this->halbjahre->registrationMatrix();
        $halbjahre = $matrix['halbjahre'];
        $kurse = $matrix['kurse'];

        try {
            $this->botProtection->assertValid($data, $this->clientIp($request));
            if (!$this->isFormReady($halbjahre, $kurse)) {
                throw new \RuntimeException(
                    'Die Anmeldung ist noch nicht vollständig eingerichtet (4 Halbjahre mit je mind. 2 Kursen).'
                );
            }

            $byId = [
                'name' => (string) ($data['name'] ?? ''),
                'tutorium' => (string) ($data['tutorium'] ?? ''),
                'email' => (string) ($data['email'] ?? ''),
            ];
            foreach ($halbjahre as $hj) {
                $hjId = (int) $hj['id'];
                $kursId = (int) ($data['wahl'][$hjId] ?? $data['wahl'][(string) $hjId] ?? 0);
                $altId = (int) ($data['alt'][$hjId] ?? $data['alt'][(string) $hjId] ?? 0);
                $this->assertKursInHalbjahr($kursId, $hjId);
                $this->assertKursInHalbjahr($altId, $hjId);
                $byId['hj' . $hjId . '_kurs_id'] = $kursId;
                $byId['hj' . $hjId . '_alt_id'] = $altId;
            }

            $anmeldung = $this->anmeldungen->register($byId, !$open && $adminTest);
            $_SESSION['flash_success'] = 'Ihre Anmeldung wurde gespeichert. Eine Bestätigung wurde per E-Mail versendet.';
            $_SESSION['flash_success_token'] = (string) $anmeldung['token'];
            return $response
                ->withHeader('Location', '/')
                ->withStatus(302);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(400), 'public/register.twig', [
                'halbjahre' => $halbjahre,
                'kurse' => $kurse,
                'form_ready' => $this->isFormReady($halbjahre, $kurse),
                'error' => $e->getMessage(),
                'old' => $data,
                'admin_test_mode' => !$open && $adminTest,
                'kursbeschreibungen' => $this->kursbeschreibungenUrl(),
                'kursbeschreibungen_html' => $this->kursbeschreibungenHtml(),
                'bot_protection' => $this->botProtection->widgetConfig(),
            ]);
        }
    }

    public function thanks(Request $request, Response $response, array $args): Response
    {
        $anmeldung = $this->anmeldungen->findByToken((string) $args['token']);
        if ($anmeldung === null) {
            $response->getBody()->write('Anmeldung nicht gefunden.');
            return $response->withStatus(404);
        }
        return $this->view->render($response, 'public/thanks.twig', [
            'anmeldung' => $anmeldung,
        ]);
    }

    public function cancelForm(Request $request, Response $response, array $args): Response
    {
        $anmeldung = $this->anmeldungen->findByToken((string) $args['token']);
        if ($anmeldung === null) {
            $response->getBody()->write('Anmeldung nicht gefunden.');
            return $response->withStatus(404);
        }
        return $this->view->render($response, 'public/cancel.twig', [
            'anmeldung' => $anmeldung,
            'bot_protection' => $this->botProtection->widgetConfig(),
        ]);
    }

    public function cancelSubmit(Request $request, Response $response, array $args): Response
    {
        $token = (string) $args['token'];
        $data = (array) $request->getParsedBody();
        try {
            $this->botProtection->assertValid($data, $this->clientIp($request));
            $this->anmeldungen->cancel($token);
            $anmeldung = $this->anmeldungen->findByToken($token);
            return $this->view->render($response, 'public/cancel.twig', [
                'anmeldung' => $anmeldung,
                'done' => true,
                'bot_protection' => $this->botProtection->widgetConfig(),
            ]);
        } catch (\Throwable $e) {
            $anmeldung = $this->anmeldungen->findByToken($token);
            return $this->view->render($response->withStatus(400), 'public/cancel.twig', [
                'anmeldung' => $anmeldung,
                'error' => $e->getMessage(),
                'bot_protection' => $this->botProtection->widgetConfig(),
            ]);
        }
    }

    public function impressum(Request $request, Response $response): Response
    {
        return $this->renderLegalPage(
            $response,
            'Impressum',
            'public/impressum.twig',
            'impressum_url',
            'impressum_text'
        );
    }

    public function datenschutz(Request $request, Response $response): Response
    {
        return $this->renderLegalPage(
            $response,
            'Datenschutz',
            'public/datenschutz.twig',
            'datenschutz_url',
            'datenschutz_text'
        );
    }

    public function about(Request $request, Response $response): Response
    {
        $label = trim($this->settings->get('about_link_label'));
        if ($label === '') {
            $label = 'Woher kommt diese Seite';
        }
        $text = trim($this->settings->get('about_text'));

        if ($text !== '') {
            return $this->view->render($response, 'public/about.twig', [
                'page_title' => $label,
                'body' => $text,
                'missing' => false,
            ]);
        }

        return $this->view->render($response->withStatus(404), 'public/about.twig', [
            'page_title' => $label,
            'body' => '',
            'missing' => true,
        ]);
    }

    public function robotsTxt(Request $request, Response $response): Response
    {
        if ($this->settings->googleIndexingEnabled()) {
            $body = "User-agent: *\nAllow: /\nDisallow: /administrator\nDisallow: /danke/\nDisallow: /stornieren/\nDisallow: /setup\n";
            $baseUrl = $this->publicBaseUrl();
            if ($baseUrl !== '') {
                $body .= 'Sitemap: ' . $baseUrl . "/sitemap.xml\n";
            }
        } else {
            $body = "User-agent: *\nDisallow: /\n";
        }

        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function sitemapXml(Request $request, Response $response): Response
    {
        if (!$this->settings->googleIndexingEnabled()) {
            $response->getBody()->write('');
            return $response
                ->withStatus(404)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $baseUrl = $this->publicBaseUrl();
        if ($baseUrl === '') {
            $response->getBody()->write('APP_URL ist nicht konfiguriert.');
            return $response
                ->withStatus(503)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $paths = ['/', '/anmelden'];
        if (trim($this->settings->get('impressum_text')) !== '') {
            $paths[] = '/impressum';
        }
        if (trim($this->settings->get('datenschutz_text')) !== '') {
            $paths[] = '/datenschutz';
        }
        if (trim($this->settings->get('about_text')) !== '') {
            $paths[] = '/woher';
        }

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($paths as $path) {
            $loc = htmlspecialchars($baseUrl . $path, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $priority = $path === '/' ? '1.0' : ($path === '/anmelden' ? '0.9' : '0.7');
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . $loc . '</loc>';
            $xml[] = '    <changefreq>weekly</changefreq>';
            $xml[] = '    <priority>' . $priority . '</priority>';
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';

        $response->getBody()->write(implode("\n", $xml) . "\n");
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function registrationGate(Response $response): ?Response
    {
        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        if (!$open && !$adminTest) {
            return $this->view->render($response, 'public/closed.twig');
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $halbjahre
     * @param list<array<string, mixed>> $kurse
     */
    private function isFormReady(array $halbjahre, array $kurse): bool
    {
        if (count($halbjahre) !== HalbjahrService::SLOT_COUNT) {
            return false;
        }
        foreach ($halbjahre as $hj) {
            $hjId = (int) $hj['id'];
            $selectable = 0;
            foreach ($kurse as $kurs) {
                if (!empty($kurs['verfuegbar'][$hjId])) {
                    $selectable++;
                }
            }
            if ($selectable < 2) {
                return false;
            }
        }
        return true;
    }

    public function downloadKursbeschreibungen(Request $request, Response $response): Response
    {
        $stored = trim($this->settings->get('kursbeschreibungen_file'));
        if ($stored === '') {
            return $response->withStatus(404);
        }

        $path = rtrim($this->uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($stored);
        if (!is_file($path)) {
            return $response->withStatus(404);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $downloadName = $this->kursbeschreibungenDownloadName($ext);
        $params = $request->getQueryParams();
        $forceDownload = isset($params['download']);
        $accept = $request->getHeaderLine('Accept');
        $wantsHtml = str_contains($accept, 'text/html');

        // Bei normalem Klick zuerst Hilfeseite (DOCX = sonst oft leere Browserseite).
        if ($wantsHtml && !$forceDownload) {
            return $this->view->render($response, 'public/kursbeschreibungen.twig', [
                'filename' => $downloadName,
            ]);
        }

        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return $response->withStatus(500);
        }

        $body = (string) stream_get_contents($stream);
        fclose($stream);

        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $downloadName) ?: ('Kursbeschreibungen.' . $ext);
        $asciiName = str_replace(['"', '\\'], '_', $asciiName);
        $utfName = rawurlencode($downloadName);

        $response = $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . $utfName
            )
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store');

        $response->getBody()->write($body);
        return $response;
    }

    private function kursbeschreibungenUrl(): ?string
    {
        $file = trim($this->settings->get('kursbeschreibungen_file'));
        if ($file === '') {
            return null;
        }
        return '/kursbeschreibungen';
    }

    private function kursbeschreibungenDownloadName(string $fallbackExt = 'pdf'): string
    {
        $downloadName = trim($this->settings->get('kursbeschreibungen_name'));
        if ($downloadName === '') {
            $stored = trim($this->settings->get('kursbeschreibungen_file'));
            $ext = $stored !== '' ? strtolower(pathinfo($stored, PATHINFO_EXTENSION)) : $fallbackExt;
            $downloadName = 'Kursbeschreibungen.' . ($ext !== '' ? $ext : $fallbackExt);
        }
        return str_replace(["\r", "\n"], '', basename($downloadName));
    }

    private function kursbeschreibungenHtml(): ?string
    {
        $url = $this->kursbeschreibungenUrl();
        if ($url === null) {
            return null;
        }

        $text = trim($this->settings->get('kursbeschreibungen_text'));
        if ($text === '') {
            $text = 'Hier findest du eine Übersicht über die [Kursbeschreibungen].';
        }

        $linkHref = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attrs = 'href="' . $linkHref . '"';

        if (preg_match('/^(.*?)\[([^\]]+)\](.*)$/s', $text, $m)) {
            return $esc($m[1])
                . '<a ' . $attrs . '>' . $esc($m[2]) . '</a>'
                . $esc($m[3]);
        }

        return '<a ' . $attrs . '>' . $esc($text) . '</a>';
    }

    private function assertKursInHalbjahr(int $kursId, int $halbjahrId): void
    {
        if ($kursId < 1) {
            throw new \RuntimeException('Bitte für jedes Halbjahr eine 1. Wahl und eine Alternative wählen.');
        }
        if (!$this->halbjahre->isKursVerfuegbar($kursId, $halbjahrId)) {
            throw new \RuntimeException('Bitte gültige Kurse für jedes Halbjahr wählen.');
        }
    }

    private function publicBaseUrl(): string
    {
        $configured = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        if ($configured !== '') {
            return $configured;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        return $host !== '' ? $scheme . '://' . $host : '';
    }

    private function clientIp(Request $request): ?string
    {
        $server = $request->getServerParams();
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? $ip : null;
    }

    private function renderLegalPage(
        Response $response,
        string $title,
        string $template,
        string $urlKey,
        string $textKey
    ): Response {
        $text = trim($this->settings->get($textKey));
        $url = $this->settings->normalizeLegalUrl($this->settings->get($urlKey));

        if ($text !== '') {
            return $this->view->render($response, $template, [
                'title' => $title,
                'body' => $text,
                'missing' => false,
            ]);
        }

        if ($url !== '') {
            return $response
                ->withHeader('Location', $url)
                ->withStatus(302);
        }

        return $this->view->render($response->withStatus(404), $template, [
            'title' => $title,
            'body' => '',
            'missing' => true,
        ]);
    }
}
