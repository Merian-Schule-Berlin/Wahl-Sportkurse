<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\CsrfService;
use App\Services\HalbjahrService;
use App\Services\HtmlContentService;
use App\Services\SettingsService;
use App\Services\UploadService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

final class HalbjahrController
{
    public function __construct(
        private readonly Twig $view,
        private readonly HalbjahrService $halbjahre,
        private readonly CsrfService $csrf,
        private readonly SettingsService $settings,
        private readonly UploadService $uploads,
        private readonly HtmlContentService $html
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        $file = trim($this->settings->get('kursbeschreibungen_file'));
        $label = trim($this->settings->get('kursbeschreibungen_name'));
        $text = trim($this->settings->get('kursbeschreibungen_text'));
        if ($text === '') {
            $text = 'Hier findest du eine Übersicht über die [Kursbeschreibungen].';
        }

        return $this->view->render($response, 'admin/halbjahre/index.twig', [
            'halbjahre' => $this->halbjahre->withStats(),
            'kurse' => $this->halbjahre->allKurse(),
            'kursbeschreibungen_file' => $file !== '' ? $file : null,
            'kursbeschreibungen_name' => $label !== '' ? $label : null,
            'kursbeschreibungen_url' => $file !== '' ? '/kursbeschreibungen' : null,
            'kursbeschreibungen_text' => $text,
            'flash' => $flash,
            'error' => $error,
        ]);
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $hj = $this->halbjahre->find((int) $args['id']);
        if ($hj === null) {
            return $response->withStatus(404);
        }

        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        return $this->view->render($response, 'admin/halbjahre/edit.twig', [
            'halbjahr' => $hj,
            'kurse' => $this->halbjahre->kurseFor((int) $hj['id']),
            'flash' => $flash,
            'error' => $error,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $hj = $this->halbjahre->find($id);
        if ($hj === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Bitte einen Namen angeben.';
            return $response->withHeader('Location', '/administrator/halbjahre/' . $id)->withStatus(302);
        }

        $this->halbjahre->update($id, [
            'name' => $name,
            'aktiv' => !empty($data['aktiv']) ? 1 : 0,
            'sortierung' => (int) ($data['sortierung'] ?? $hj['sortierung']),
        ]);
        $_SESSION['flash'] = 'Halbjahr gespeichert.';
        return $response->withHeader('Location', '/administrator/halbjahre/' . $id)->withStatus(302);
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $this->halbjahre->toggle((int) $args['id']);
        $_SESSION['flash'] = 'Status geändert.';
        return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
    }

    public function addKurs(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Bitte einen Kursnamen angeben.';
            return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
        }

        $this->halbjahre->createKurs([
            'name' => $name,
            'bewegungsfeld' => trim((string) ($data['bewegungsfeld'] ?? '')),
        ]);
        $_SESSION['flash'] = 'Kurs angelegt – für alle Halbjahre wählbar. Bei Bedarf einzeln ausgrauen.';
        return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
    }

    public function editKursForm(Request $request, Response $response, array $args): Response
    {
        $kurs = $this->halbjahre->findKurs((int) ($args['kursId'] ?? 0));
        if ($kurs === null) {
            return $response->withStatus(404);
        }

        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        return $this->view->render($response, 'admin/halbjahre/kurs_edit.twig', [
            'kurs' => $kurs,
            'flash' => $flash,
            'error' => $error,
        ]);
    }

    public function updateKurs(Request $request, Response $response, array $args): Response
    {
        $kursId = (int) $args['kursId'];
        $kurs = $this->halbjahre->findKurs($kursId);
        if ($kurs === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $name = trim((string) ($data['name'] ?? ''));
        $redirectEdit = !empty($data['edit_beschreibung']);

        if ($name === '') {
            $_SESSION['flash_error'] = 'Bitte einen Kursnamen angeben.';
            return $response->withHeader(
                'Location',
                $redirectEdit
                    ? '/administrator/halbjahre/kurse/' . $kursId
                    : '/administrator/halbjahre'
            )->withStatus(302);
        }

        try {
            $payload = [
                'name' => $name,
                'bewegungsfeld' => trim((string) ($data['bewegungsfeld'] ?? '')),
            ];

            if ($redirectEdit) {
                $payload['beschreibung'] = $this->html->sanitize((string) ($data['beschreibung'] ?? ''));
                $bild = $kurs['teaser_bild'] ?? null;
                /** @var UploadedFileInterface|null $upload */
                $upload = $files['teaser_bild'] ?? null;
                if ($upload !== null && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
                    $tmp = tempnam(sys_get_temp_dir(), 'upl');
                    if ($tmp === false) {
                        throw new \RuntimeException('Temporäre Datei fehlgeschlagen.');
                    }
                    $upload->moveTo($tmp);
                    $new = $this->uploads->storeImage([
                        'name' => $upload->getClientFilename() ?? 'kurs.jpg',
                        'type' => $upload->getClientMediaType() ?? '',
                        'tmp_name' => $tmp,
                        'error' => UPLOAD_ERR_OK,
                        'size' => $upload->getSize() ?? 0,
                    ], 'kurs');
                    $this->uploads->delete($bild !== null && $bild !== '' ? (string) $bild : null);
                    $bild = $new;
                }
                if (!empty($data['remove_image'])) {
                    $this->uploads->delete($bild !== null && $bild !== '' ? (string) $bild : null);
                    $bild = null;
                }
                $payload['teaser_bild'] = $bild;
            }

            $this->halbjahre->updateKurs($kursId, $payload);
            $_SESSION['flash'] = 'Kurs gespeichert.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader(
                'Location',
                $redirectEdit
                    ? '/administrator/halbjahre/kurse/' . $kursId
                    : '/administrator/halbjahre'
            )->withStatus(302);
        }

        return $response->withHeader(
            'Location',
            $redirectEdit
                ? '/administrator/halbjahre/kurse/' . $kursId
                : '/administrator/halbjahre'
        )->withStatus(302);
    }

    public function reorderKurse(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $order = $data['order'] ?? [];
        if (!is_array($order)) {
            $order = [];
        }
        $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        try {
            $this->halbjahre->reorderKurse(array_map('intval', $order));
            if ($isAjax) {
                $response->getBody()->write(json_encode(['ok' => true], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json');
            }
            $_SESSION['flash'] = 'Reihenfolge gespeichert.';
        } catch (\Throwable $e) {
            if ($isAjax) {
                $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
    }

    public function deleteKurs(Request $request, Response $response, array $args): Response
    {
        $kursId = (int) $args['kursId'];
        if ($this->halbjahre->findKurs($kursId) === null) {
            return $response->withStatus(404);
        }

        try {
            $kurs = $this->halbjahre->findKurs($kursId);
            $this->halbjahre->deleteKurs($kursId);
            if ($kurs !== null) {
                $this->uploads->delete(
                    trim((string) ($kurs['teaser_bild'] ?? '')) !== ''
                        ? (string) $kurs['teaser_bild']
                        : null
                );
            }
            $_SESSION['flash'] = 'Kurs gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        $redirect = (string) (($request->getParsedBody()['redirect'] ?? '') ?: '/administrator/halbjahre');
        if (!str_starts_with($redirect, '/administrator/')) {
            $redirect = '/administrator/halbjahre';
        }
        return $response->withHeader('Location', $redirect)->withStatus(302);
    }

    public function toggleKursVerfuegbar(Request $request, Response $response, array $args): Response
    {
        $hjId = (int) $args['id'];
        $kursId = (int) $args['kursId'];
        if ($this->halbjahre->find($hjId) === null || $this->halbjahre->findKurs($kursId) === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        $verfuegbar = !empty($data['verfuegbar']);
        $this->halbjahre->setKursVerfuegbar($kursId, $hjId, $verfuegbar);
        $_SESSION['flash'] = $verfuegbar ? 'Kurs ist wieder wählbar.' : 'Kurs ausgegraut.';
        return $response->withHeader('Location', '/administrator/halbjahre/' . $hjId)->withStatus(302);
    }

    public function saveKursbeschreibungenText(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $text = trim((string) ($data['kursbeschreibungen_text'] ?? ''));
        if ($text === '') {
            $_SESSION['flash_error'] = 'Bitte einen Hinweistext angeben.';
            return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
        }
        if (!str_contains($text, '[') || !str_contains($text, ']')) {
            // Ohne Markierung den gesamten Text verlinken – dafür Klammern ergänzen
            $text = '[' . $text . ']';
        }
        $this->settings->setMany(['kursbeschreibungen_text' => $text]);
        $_SESSION['flash'] = 'Hinweistext gespeichert.';
        return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
    }

    public function uploadKursbeschreibungen(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles();
        /** @var UploadedFileInterface|null $file */
        $file = $files['kursbeschreibungen'] ?? null;
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            $_SESSION['flash_error'] = 'Bitte eine Datei auswählen (PDF, DOC oder DOCX).';
            return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
        }

        try {
            $tmp = tempnam(sys_get_temp_dir(), 'upl');
            if ($tmp === false) {
                throw new \RuntimeException('Temporäre Datei fehlgeschlagen.');
            }
            $file->moveTo($tmp);
            $new = $this->uploads->storeDocument([
                'name' => $file->getClientFilename() ?? 'kursbeschreibungen.pdf',
                'type' => $file->getClientMediaType() ?? '',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => $file->getSize() ?? 0,
            ], 'kursbeschreibungen');

            $old = trim($this->settings->get('kursbeschreibungen_file'));
            if ($old !== '' && $old !== $new) {
                $this->uploads->delete($old);
            }

            $this->settings->setMany([
                'kursbeschreibungen_file' => $new,
                'kursbeschreibungen_name' => (string) ($file->getClientFilename() ?? $new),
            ]);
            $_SESSION['flash'] = 'Kursbeschreibungen hochgeladen.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
    }

    public function deleteKursbeschreibungen(Request $request, Response $response): Response
    {
        $old = trim($this->settings->get('kursbeschreibungen_file'));
        $this->uploads->delete($old !== '' ? $old : null);
        $this->settings->setMany([
            'kursbeschreibungen_file' => '',
            'kursbeschreibungen_name' => '',
        ]);
        $_SESSION['flash'] = 'Kursbeschreibungen entfernt.';
        return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
    }
}
