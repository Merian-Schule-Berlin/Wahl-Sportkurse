<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AnmeldungService;
use App\Services\AuthService;
use App\Services\HalbjahrService;
use App\Services\SettingsService;
use App\Services\UpdateService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(
        private readonly Twig $view,
        private readonly HalbjahrService $halbjahre,
        private readonly AnmeldungService $anmeldungen,
        private readonly AuthService $auth,
        private readonly SettingsService $settings,
        private readonly UpdateService $updater
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $updateNotice = $_SESSION['update_notice'] ?? $this->updater->updateNotice();
        if ($updateNotice !== null) {
            $_SESSION['update_notice'] = $updateNotice;
        } else {
            unset($_SESSION['update_notice']);
        }

        return $this->view->render($response, 'admin/dashboard.twig', [
            'halbjahre' => $this->halbjahre->withStats(),
            'active_count' => $this->anmeldungen->countActive(),
            'admin_username' => $this->auth->username() ?? '',
            'flash' => $flash,
            'registration_window' => $this->registrationWindow(),
            'update_notice' => $updateNotice,
        ]);
    }

    /**
     * @return array{open:bool,state:string,label:string,start:string,end:string,jahrgang:string}
     */
    private function registrationWindow(): array
    {
        $window = $this->settings->currentRegistrationWindow();
        $start = $window['start'];
        $end = $window['end'];
        $now = time();
        $open = $this->settings->isRegistrationOpen();

        $state = 'open';
        $label = 'Geöffnet';

        if ($start !== '') {
            $startTs = strtotime($start);
            if ($startTs !== false && $now < $startTs) {
                $state = 'upcoming';
                $label = 'Noch nicht geöffnet';
            }
        }
        if ($state !== 'upcoming' && $end !== '') {
            $endTs = strtotime($end);
            if ($endTs !== false && $now > $endTs) {
                $state = 'closed';
                $label = 'Geschlossen';
            }
        }
        if ($open && $state === 'open' && $start === '' && $end === '') {
            $label = 'Geöffnet (ohne Zeitraum)';
        } elseif (!$open && $state === 'open') {
            $state = 'closed';
            $label = 'Geschlossen';
        }

        return [
            'open' => $open,
            'state' => $state,
            'label' => $label,
            'start' => $start,
            'end' => $end,
            'jahrgang' => $window['name'],
        ];
    }
}
