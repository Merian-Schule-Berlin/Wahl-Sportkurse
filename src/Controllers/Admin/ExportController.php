<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AnmeldungService;
use App\Services\ExportService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ExportController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AnmeldungService $anmeldungen,
        private readonly ExportService $export,
        private readonly SettingsService $settings
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        return $this->view->render($response, 'admin/listen.twig', [
            'anmeldungen' => $this->anmeldungen->allActive(),
            'nach_kurs' => $this->anmeldungen->teilnehmerNachKurs(),
            'active_count' => $this->anmeldungen->countActive(),
            'flash' => $flash,
            'error' => $error,
        ]);
    }

    public function deleteAnmeldung(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id < 1 || !$this->anmeldungen->deleteById($id)) {
            $_SESSION['flash_error'] = 'Anmeldung konnte nicht gelöscht werden.';
        } else {
            $_SESSION['flash'] = 'Anmeldung gelöscht.';
        }
        return $response->withHeader('Location', '/administrator/listen')->withStatus(302);
    }

    public function deleteSelected(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $ids = $data['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $count = $this->anmeldungen->deleteByIds($ids);
        if ($count < 1) {
            $_SESSION['flash_error'] = 'Keine Anmeldung ausgewählt.';
        } else {
            $_SESSION['flash'] = $count === 1
                ? '1 Anmeldung gelöscht.'
                : $count . ' Anmeldungen gelöscht.';
        }
        return $response->withHeader('Location', '/administrator/listen')->withStatus(302);
    }

    public function downloadCsv(Request $request, Response $response): Response
    {
        $csv = $this->export->csvAll();
        $filename = 'sportkurs-anmeldungen_' . date('Y-m-d') . '.csv';
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function downloadPdf(Request $request, Response $response): Response
    {
        $logo = $this->settings->get('site_logo');
        $pdf = $this->export->pdfAll($logo !== '' ? $logo : null);
        $filename = 'sportkurs-anmeldungen_' . date('Y-m-d') . '.pdf';
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
