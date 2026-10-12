<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\CsrfService;
use App\Services\HalbjahrService;
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
        private readonly UploadService $uploads
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        $file = trim($this->settings->get('kursbeschreibungen_file'));
        $label = trim($this->settings->get('kursbeschreibungen_name'));

        return $this->view->render($response, 'admin/halbjahre/index.twig', [
            'halbjahre' => $this->halbjahre->withStats(),
            'kurse' => $this->halbjahre->allKurse(),
            'kursbeschreibungen_file' => $file !== '' ? $file : null,
            'kursbeschreibungen_name' => $label !== '' ? $label : null,
            'kursbeschreibungen_url' => $file !== '' ? '/uploads/' . rawurlencode(basename($file)) : null,
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

    public function updateKurs(Request $request, Response $response, array $args): Response
    {
        $kursId = (int) $args['kursId'];
        $kurs = $this->halbjahre->findKurs($kursId);
        if ($kurs === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Bitte einen Kursnamen angeben.';
            return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
        }

        $this->halbjahre->updateKurs($kursId, [
            'name' => $name,
            'bewegungsfeld' => trim((string) ($data['bewegungsfeld'] ?? '')),
            'sortierung' => (int) ($data['sortierung'] ?? $kurs['sortierung']),
        ]);
        $_SESSION['flash'] = 'Kurs gespeichert.';
        return $response->withHeader('Location', '/administrator/halbjahre')->withStatus(302);
    }

    public function deleteKurs(Request $request, Response $response, array $args): Response
    {
        $kursId = (int) $args['kursId'];
        if ($this->halbjahre->findKurs($kursId) === null) {
            return $response->withStatus(404);
        }

        try {
            $this->halbjahre->deleteKurs($kursId);
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
