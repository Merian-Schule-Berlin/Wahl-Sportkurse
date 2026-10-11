<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Lightweight schema upgrades for existing installations.
 */
final class SchemaMigrator
{
    public function migrate(PDO $pdo): void
    {
        // Fresh installs use schema.sql; keep hook for future upgrades.
    }
}
