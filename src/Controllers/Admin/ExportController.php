<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AnmeldungService;
use App\Services\ExportService;
use App\Services\HalbjahrService;
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
        private readonly SettingsService $settings,
        private readonly HalbjahrService $halbjahre
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        [$jahrgang, $sort, $dir, $search, $halbjahrIds, $tab] = $this->listParams($request);

        return $this->view->render($response, 'admin/listen.twig', [
            'anmeldungen' => $this->anmeldungen->allActive($jahrgang, $sort, $dir, $search),
            'nach_kurs' => $this->anmeldungen->teilnehmerNachKurs($jahrgang, $search, $halbjahrIds),
            'nach_kurs_alle' => $this->anmeldungen->teilnehmerNachKurs($jahrgang, $search, null),
            'active_count' => $this->anmeldungen->countActive($jahrgang, $search),
            'jahrgaenge' => $this->anmeldungen->listJahrgaenge(),
            'jahrgang' => $jahrgang,
            'aktueller_jahrgang' => $this->anmeldungen->resolveJahrgang(),
            'sort' => $sort,
            'dir' => $dir,
            'search' => $search,
            'halbjahre' => $this->halbjahre->all(),
            'selected_halbjahre' => $halbjahrIds,
            'tab' => $tab,
            'flash' => $flash,
            'error' => $error,
        ]);
    }

    public function setZuordnung(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $hj = (int) ($data['halbjahr_id'] ?? $args['hj'] ?? 0);
        $useAlt = (string) ($data['verwendet_alt'] ?? '') === '1';

        $q = $request->getQueryParams();
        $jahrgang = trim((string) ($data['jahrgang'] ?? $q['jahrgang'] ?? ''));
        if ($jahrgang === '') {
            $jahrgang = $this->anmeldungen->resolveJahrgang();
        }
        $search = trim((string) ($data['search'] ?? $q['q'] ?? ''));
        $halbjahre = $data['hj'] ?? $q['hj'] ?? null;
        if (!is_array($halbjahre)) {
            $halbjahre = null;
        }
        $hjIds = $this->anmeldungen->normalizeHalbjahre($halbjahre);
        $tab = (string) ($data['tab'] ?? 'uebersicht');
        if (!in_array($tab, ['alle', 'kurse', 'uebersicht'], true)) {
            $tab = 'uebersicht';
        }

        if ($id < 1 || !$this->anmeldungen->setVerwendetAlt($id, $hj, $useAlt)) {
            $_SESSION['flash_error'] = 'Zuordnung konnte nicht geändert werden.';
        } else {
            $_SESSION['flash'] = $useAlt
                ? 'Alternative für Halbjahr ' . $hj . ' zugewiesen.'
                : '1. Wahl für Halbjahr ' . $hj . ' zugewiesen.';
        }

        return $response
            ->withHeader('Location', $this->listenUrl($jahrgang, '', '', $search, $hjIds, $tab))
            ->withStatus(302);
    }

    public function deleteAnmeldung(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        [$jahrgang, $sort, $dir, $search, $halbjahrIds, $tab] = $this->listParams($request);
        if ($id < 1 || !$this->anmeldungen->deleteById($id)) {
            $_SESSION['flash_error'] = 'Anmeldung konnte nicht gelöscht werden.';
        } else {
            $_SESSION['flash'] = 'Anmeldung gelöscht.';
        }
        return $response
            ->withHeader('Location', $this->listenUrl($jahrgang, $sort, $dir, $search, $halbjahrIds, $tab))
            ->withStatus(302);
    }

    public function deleteSelected(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $ids = $data['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $jahrgang = trim((string) ($data['jahrgang'] ?? $this->anmeldungen->resolveJahrgang()));
        $sort = (string) ($data['sort'] ?? AnmeldungService::SORT_NACHNAME);
        $dir = strtolower((string) ($data['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $search = trim((string) ($data['search'] ?? ''));
        $halbjahre = $data['hj'] ?? null;
        $hjIds = $this->anmeldungen->normalizeHalbjahre(is_array($halbjahre) ? $halbjahre : null);
        $tab = (string) ($data['tab'] ?? 'uebersicht');
        if (!in_array($tab, ['alle', 'kurse', 'uebersicht'], true)) {
            $tab = 'uebersicht';
        }
        $count = $this->anmeldungen->deleteByIds($ids);
        if ($count < 1) {
            $_SESSION['flash_error'] = 'Keine Anmeldung ausgewählt.';
        } else {
            $_SESSION['flash'] = $count === 1
                ? '1 Anmeldung gelöscht.'
                : $count . ' Anmeldungen gelöscht.';
        }
        return $response
            ->withHeader('Location', $this->listenUrl($jahrgang, $sort, $dir, $search, $hjIds, $tab))
            ->withStatus(302);
    }

    public function downloadCsv(Request $request, Response $response): Response
    {
        [$jahrgang] = $this->listParams($request);
        $csv = $this->export->csvAll($jahrgang);
        $safe = str_replace('/', '-', $jahrgang);
        $filename = 'sportkurs-anmeldungen_' . $safe . '_' . date('Y-m-d') . '.csv';
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function downloadPdf(Request $request, Response $response): Response
    {
        [$jahrgang] = $this->listParams($request);
        $logo = $this->settings->get('site_logo');
        $pdf = $this->export->pdfAll($logo !== '' ? $logo : null, $jahrgang);
        $safe = str_replace('/', '-', $jahrgang);
        $filename = 'sportkurs-anmeldungen_' . $safe . '_' . date('Y-m-d') . '.pdf';
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * @return array{0:string,1:string,2:string,3:string,4:list<int>,5:string}
     */
    private function listParams(Request $request): array
    {
        $q = $request->getQueryParams();
        $jahrgang = trim((string) ($q['jahrgang'] ?? ''));
        if ($jahrgang === '') {
            $jahrgang = $this->anmeldungen->resolveJahrgang();
        }
        $allowedSort = [
            AnmeldungService::SORT_NACHNAME,
            AnmeldungService::SORT_VORNAME,
            AnmeldungService::SORT_TUTORIUM,
            AnmeldungService::SORT_EMAIL,
            AnmeldungService::SORT_HJ1,
            AnmeldungService::SORT_HJ2,
            AnmeldungService::SORT_HJ3,
            AnmeldungService::SORT_HJ4,
            AnmeldungService::SORT_DATE,
            'name',
        ];
        $sort = (string) ($q['sort'] ?? AnmeldungService::SORT_NACHNAME);
        if ($sort === 'name') {
            $sort = AnmeldungService::SORT_NACHNAME;
        }
        if (!in_array($sort, $allowedSort, true)) {
            $sort = AnmeldungService::SORT_NACHNAME;
        }
        $dir = strtolower((string) ($q['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $search = trim((string) ($q['q'] ?? $q['search'] ?? ''));
        $hjRaw = $q['hj'] ?? null;
        $halbjahrIds = $this->anmeldungen->normalizeHalbjahre(is_array($hjRaw) ? $hjRaw : null);
        $tab = (string) ($q['tab'] ?? 'uebersicht');
        if (!in_array($tab, ['alle', 'kurse', 'uebersicht'], true)) {
            $tab = 'uebersicht';
        }
        return [$jahrgang, $sort, $dir, $search, $halbjahrIds, $tab];
    }

    /**
     * @param list<int> $halbjahre
     */
    private function listenUrl(
        string $jahrgang,
        string $sort = '',
        string $dir = '',
        string $search = '',
        array $halbjahre = [],
        string $tab = 'uebersicht'
    ): string {
        $params = [
            'jahrgang' => $jahrgang,
            'tab' => $tab,
        ];
        if ($sort !== '') {
            $params['sort'] = $sort;
            $params['dir'] = $dir !== '' ? $dir : 'asc';
        }
        if ($search !== '') {
            $params['q'] = $search;
        }
        if ($halbjahre !== []) {
            $params['hj'] = $halbjahre;
        }
        return '/administrator/listen?' . http_build_query($params);
    }
}
