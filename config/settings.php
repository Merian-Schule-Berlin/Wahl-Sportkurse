<?php

declare(strict_types=1);

return [
    'display_error_details' => ($_ENV['APP_DEBUG'] ?? '0') === '1',
    'app_name' => $_ENV['APP_NAME'] ?? 'Wahl-Sportkurse',
    'app_url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
    'base_path' => $_ENV['BASE_PATH'] ?? '',
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'name' => $_ENV['DB_NAME'] ?? '',
        'user' => $_ENV['DB_USER'] ?? '',
        'pass' => $_ENV['DB_PASS'] ?? '',
    ],
    'mail' => [
        'from' => $_ENV['MAIL_FROM'] ?? '',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'Wahl-Sportkurse',
        'subject' => $_ENV['MAIL_SUBJECT'] ?? 'Ihre Sportkurs-Wahl',
    ],
];
