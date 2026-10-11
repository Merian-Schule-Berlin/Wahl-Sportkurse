<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\CsrfService;
use App\Services\HalbjahrService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class HalbjahrController
{
    public function __construct(
        private readonly Twig $view,
        private readonly HalbjahrService $halbjahre,
        private readonly CsrfService $csrf
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        return $this->view->render($response, 'admin/halbjahre/index.twig', [
            'halbjahre' => $this->halbjahre->withStats(),
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

    public function addKurs(Request $request, Response $response, array $args): Response
    {
        $hjId = (int) $args['id'];
        if ($this->halbjahre->find($hjId) === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        $name = trim((string) ($data['name'] ?? ''));
        $kap = max(1, (int) ($data['kapazitaet'] ?? 20));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Bitte einen Kursnamen angeben.';
            return $response->withHeader('Location', '/administrator/halbjahre/' . $hjId)->withStatus(302);
        }

        $this->halbjahre->createKurs([
            'halbjahr_id' => $hjId,
            'name' => $name,
            'kapazitaet' => $kap,
            'aktiv' => 1,
        ]);
        $_SESSION['flash'] = 'Kurs angelegt.';
        return $response->withHeader('Location', '/administrator/halbjahre/' . $hjId)->withStatus(302);
    }

    public function updateKurs(Request $request, Response $response, array $args): Response
    {
        $hjId = (int) $args['id'];
        $kursId = (int) $args['kursId'];
        $kurs = $this->halbjahre->findKurs($kursId);
        if ($kurs === null || (int) $kurs['halbjahr_id'] !== $hjId) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Bitte einen Kursnamen angeben.';
            return $response->withHeader('Location', '/administrator/halbjahre/' . $hjId)->withStatus(302);
        }

        $this->halbjahre->updateKurs($kursId, [
            'name' => $name,
            'kapazitaet' => max(1, (int) ($data['kapazitaet'] ?? 20)),
            'aktiv' => !empty($data['aktiv']) ? 1 : 0,
            'sortierung' => (int) ($data['sortierung'] ?? $kurs['sortierung']),
        ]);
        $_SESSION['flash'] = 'Kurs gespeichert.';
        return $response->withHeader('Location', '/administrator/halbjahre/' . $hjId)->withStatus(302);
    }

    public function deleteKurs(Request $request, Response $response, array $args): Response
    {
        $hjId = (int) $args['id'];
        $kursId = (int) $args['kursId'];
        $kurs = $this->halbjahre->findKurs($kursId);
        if ($kurs === null || (int) $kurs['halbjahr_id'] !== $hjId) {
            return $response->withStatus(404);
        }

        try {
            $this->halbjahre->deleteKurs($kursId);
            $_SESSION['flash'] = 'Kurs gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/administrator/halbjahre/' . $hjId)->withStatus(302);
    }
}
