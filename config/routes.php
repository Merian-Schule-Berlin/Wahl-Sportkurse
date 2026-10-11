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
            $admin->get('/halbjahre/{id}', [HalbjahrController::class, 'editForm']);
            $admin->post('/halbjahre/{id}', [HalbjahrController::class, 'update']);
            $admin->post('/halbjahre/{id}/toggle', [HalbjahrController::class, 'toggle']);
            $admin->post('/halbjahre/{id}/kurse', [HalbjahrController::class, 'addKurs']);
            $admin->post('/halbjahre/{id}/kurse/{kursId}', [HalbjahrController::class, 'updateKurs']);
            $admin->post('/halbjahre/{id}/kurse/{kursId}/delete', [HalbjahrController::class, 'deleteKurs']);

            $admin->get('/listen', [ExportController::class, 'index']);
            $admin->get('/listen/anmeldungen.csv', [ExportController::class, 'downloadCsv']);
            $admin->get('/listen/anmeldungen.pdf', [ExportController::class, 'downloadPdf']);

            $admin->get('/einstellungen', [SettingsController::class, 'form']);
            $admin->post('/einstellungen', [SettingsController::class, 'save']);
            $admin->post('/einstellungen/benutzer', [SettingsController::class, 'createUser']);
            $admin->post('/einstellungen/benutzer/{id}/delete', [SettingsController::class, 'deleteUser']);
            $admin->post('/einstellungen/daten-loeschen', [SettingsController::class, 'wipeData']);
            $admin->post('/einstellungen/indexierung', [SettingsController::class, 'saveIndexing']);
            $admin->post('/einstellungen/bot-schutz', [SettingsController::class, 'saveBotProtection']);
        })->add(AdminAuthMiddleware::class);
    });
};
