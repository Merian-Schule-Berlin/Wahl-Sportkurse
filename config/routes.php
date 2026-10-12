<?php

declare(strict_types=1);

use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ExportController;
use App\Controllers\Admin\HalbjahrController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\PublicController;
use App\Controllers\SetupController;
use App\Middleware\AdminAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/setup', [SetupController::class, 'show']);
    $app->post('/setup/test-db', [SetupController::class, 'testDb']);
    $app->post('/setup/test-mail', [SetupController::class, 'testMail']);
    $app->post('/setup/install', [SetupController::class, 'install']);

    $app->get('/', [PublicController::class, 'home']);
    $app->get('/anmelden', [PublicController::class, 'registerForm']);
    $app->post('/anmelden', [PublicController::class, 'registerSubmit']);
    $app->get('/kursbeschreibungen', [PublicController::class, 'downloadKursbeschreibungen']);
    $app->get('/danke/{token}', [PublicController::class, 'thanks']);
    $app->get('/stornieren/{token}', [PublicController::class, 'cancelForm']);
    $app->post('/stornieren/{token}', [PublicController::class, 'cancelSubmit']);
    $app->get('/impressum', [PublicController::class, 'impressum']);
    $app->get('/datenschutz', [PublicController::class, 'datenschutz']);
    $app->get('/woher', [PublicController::class, 'about']);
    $app->get('/robots.txt', [PublicController::class, 'robotsTxt']);
    $app->get('/sitemap.xml', [PublicController::class, 'sitemapXml']);

    $app->group('/administrator', function (RouteCollectorProxy $group): void {
        $group->get('/login', [AuthController::class, 'loginForm']);
        $group->post('/login', [AuthController::class, 'loginSubmit']);
        $group->post('/logout', [AuthController::class, 'logout']);

        $group->group('', function (RouteCollectorProxy $admin): void {
            $admin->get('', [DashboardController::class, 'index']);
            $admin->get('/', [DashboardController::class, 'index']);

            $admin->get('/halbjahre', [HalbjahrController::class, 'index']);
            $admin->post('/halbjahre/kurse', [HalbjahrController::class, 'addKurs']);
            $admin->post('/halbjahre/kurse/{kursId}', [HalbjahrController::class, 'updateKurs']);
            $admin->post('/halbjahre/kurse/{kursId}/delete', [HalbjahrController::class, 'deleteKurs']);
            $admin->post('/halbjahre/kursbeschreibungen', [HalbjahrController::class, 'uploadKursbeschreibungen']);
            $admin->post('/halbjahre/kursbeschreibungen/text', [HalbjahrController::class, 'saveKursbeschreibungenText']);
            $admin->post('/halbjahre/kursbeschreibungen/delete', [HalbjahrController::class, 'deleteKursbeschreibungen']);
            $admin->get('/halbjahre/{id}', [HalbjahrController::class, 'editForm']);
            $admin->post('/halbjahre/{id}', [HalbjahrController::class, 'update']);
            $admin->post('/halbjahre/{id}/toggle', [HalbjahrController::class, 'toggle']);
            $admin->post('/halbjahre/{id}/kurse/{kursId}/verfuegbar', [HalbjahrController::class, 'toggleKursVerfuegbar']);

            $admin->get('/listen', [ExportController::class, 'index']);
            $admin->get('/listen/anmeldungen.csv', [ExportController::class, 'downloadCsv']);
            $admin->get('/listen/anmeldungen.pdf', [ExportController::class, 'downloadPdf']);
            $admin->post('/listen/anmeldungen/delete', [ExportController::class, 'deleteSelected']);
            $admin->post('/listen/anmeldungen/{id}/delete', [ExportController::class, 'deleteAnmeldung']);
            $admin->post('/listen/anmeldungen/{id}/zuordnung', [ExportController::class, 'setZuordnung']);
            $admin->get('/einstellungen', [SettingsController::class, 'form']);
            $admin->post('/einstellungen', [SettingsController::class, 'save']);
            $admin->post('/einstellungen/jahrgaenge', [SettingsController::class, 'createJahrgang']);
            $admin->post('/einstellungen/jahrgaenge/{id}', [SettingsController::class, 'updateJahrgang']);
            $admin->post('/einstellungen/jahrgaenge/{id}/aktuell', [SettingsController::class, 'setAktuellerJahrgang']);
            $admin->post('/einstellungen/jahrgaenge/{id}/delete', [SettingsController::class, 'deleteJahrgang']);
            $admin->post('/einstellungen/benutzer', [SettingsController::class, 'createUser']);
            $admin->post('/einstellungen/benutzer/{id}/delete', [SettingsController::class, 'deleteUser']);
            $admin->post('/einstellungen/daten-loeschen', [SettingsController::class, 'wipeData']);
            $admin->post('/einstellungen/indexierung', [SettingsController::class, 'saveIndexing']);
            $admin->post('/einstellungen/bot-schutz', [SettingsController::class, 'saveBotProtection']);
            $admin->post('/einstellungen/update-quelle', [SettingsController::class, 'saveUpdateSource']);
            $admin->post('/einstellungen/update-check', [SettingsController::class, 'checkUpdate']);
            $admin->post('/einstellungen/update', [SettingsController::class, 'runUpdate']);
        })->add(AdminAuthMiddleware::class);
    });
};
