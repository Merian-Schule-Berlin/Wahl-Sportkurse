<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class SchemaMigrator
{
    public function migrate(PDO $pdo): void
    {
        try {
            $this->ensureKursHalbjahr($pdo);
            $this->ensureBewegungsfeld($pdo);
        } catch (\Throwable) {
            // Schema-Migration darf den Request nicht abbrechen.
        }
    }

    private function ensureBewegungsfeld(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'sportkurse')) {
            return;
        }
        if (!$this->columnExists($pdo, 'sportkurse', 'bewegungsfeld')) {
            $pdo->exec(
                "ALTER TABLE sportkurse
                 ADD COLUMN bewegungsfeld VARCHAR(200) NOT NULL DEFAULT '' AFTER name"
            );
        }
    }

    private function ensureKursHalbjahr(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'sportkurse') || !$this->tableExists($pdo, 'halbjahre')) {
            return;
        }

        if (!$this->tableExists($pdo, 'kurs_halbjahr')) {
            $pdo->exec(
                'CREATE TABLE kurs_halbjahr (
                    kurs_id INT UNSIGNED NOT NULL,
                    halbjahr_id INT UNSIGNED NOT NULL,
                    verfuegbar TINYINT(1) NOT NULL DEFAULT 1,
                    PRIMARY KEY (kurs_id, halbjahr_id),
                    CONSTRAINT fk_kurs_halbjahr_kurs FOREIGN KEY (kurs_id) REFERENCES sportkurse(id) ON DELETE CASCADE,
                    CONSTRAINT fk_kurs_halbjahr_hj FOREIGN KEY (halbjahr_id) REFERENCES halbjahre(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if ($this->columnExists($pdo, 'sportkurse', 'halbjahr_id')) {
            $kurse = $pdo->query('SELECT id, halbjahr_id, aktiv FROM sportkurse')->fetchAll(PDO::FETCH_ASSOC);
            $hjIds = $pdo->query('SELECT id FROM halbjahre')->fetchAll(PDO::FETCH_COLUMN);
            $ins = $pdo->prepare(
                'INSERT IGNORE INTO kurs_halbjahr (kurs_id, halbjahr_id, verfuegbar) VALUES (?, ?, ?)'
            );
            foreach ($kurse as $kurs) {
                $avail = (int) ($kurs['aktiv'] ?? 1);
                foreach ($hjIds as $hjId) {
                    $ins->execute([(int) $kurs['id'], (int) $hjId, $avail]);
                }
            }

            foreach ($this->foreignKeysOnColumn($pdo, 'sportkurse', 'halbjahr_id') as $fk) {
                $pdo->exec('ALTER TABLE sportkurse DROP FOREIGN KEY `' . str_replace('`', '``', $fk) . '`');
            }
            $pdo->exec('ALTER TABLE sportkurse DROP COLUMN halbjahr_id');
        }

        $pdo->exec(
            'INSERT IGNORE INTO kurs_halbjahr (kurs_id, halbjahr_id, verfuegbar)
             SELECT k.id, h.id, 1
             FROM sportkurse k
             CROSS JOIN halbjahre h'
        );
    }

    /** @return list<string> */
    private function foreignKeysOnColumn(PDO $pdo, string $table, string $column): array
    {
        $stmt = $pdo->prepare(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c
               AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return array_values(array_unique(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
}