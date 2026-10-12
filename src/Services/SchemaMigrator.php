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
            $this->ensureJahrgang($pdo);
            $this->ensureVornameNachname($pdo);
            $this->ensureZuordnungAlt($pdo);
            $this->ensureJahrgaengeTabelle($pdo);
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

    private function ensureJahrgang(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'anmeldungen')) {
            return;
        }
        if (!$this->columnExists($pdo, 'anmeldungen', 'jahrgang')) {
            $pdo->exec(
                "ALTER TABLE anmeldungen
                 ADD COLUMN jahrgang VARCHAR(16) NOT NULL DEFAULT '' AFTER email"
            );
        }

        $default = AnmeldungService::defaultSchoolYear();
        $pdo->prepare(
            "UPDATE anmeldungen SET jahrgang = :j WHERE jahrgang = '' OR jahrgang IS NULL"
        )->execute(['j' => $default]);

        if ($this->tableExists($pdo, 'settings')) {
            $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('aktueller_jahrgang', :j)
                 ON DUPLICATE KEY UPDATE setting_key = setting_key"
            )->execute(['j' => $default]);
        }

        $idx = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'anmeldungen'
               AND INDEX_NAME = 'idx_anmeldungen_jahrgang'"
        )->fetchColumn();
        if ((int) $idx === 0) {
            $pdo->exec('CREATE INDEX idx_anmeldungen_jahrgang ON anmeldungen (jahrgang, status)');
        }
    }

    private function ensureVornameNachname(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'anmeldungen')) {
            return;
        }

        $hasVorname = $this->columnExists($pdo, 'anmeldungen', 'vorname');
        $hasNachname = $this->columnExists($pdo, 'anmeldungen', 'nachname');
        $hasName = $this->columnExists($pdo, 'anmeldungen', 'name');

        if (!$hasVorname) {
            $pdo->exec(
                "ALTER TABLE anmeldungen
                 ADD COLUMN vorname VARCHAR(100) NOT NULL DEFAULT '' AFTER id"
            );
        }
        if (!$hasNachname) {
            $after = $this->columnExists($pdo, 'anmeldungen', 'vorname') ? 'vorname' : 'id';
            $pdo->exec(
                "ALTER TABLE anmeldungen
                 ADD COLUMN nachname VARCHAR(100) NOT NULL DEFAULT '' AFTER `{$after}`"
            );
        }

        if ($hasName) {
            $rows = $pdo->query('SELECT id, name, vorname, nachname FROM anmeldungen')->fetchAll(PDO::FETCH_ASSOC);
            $upd = $pdo->prepare(
                'UPDATE anmeldungen SET vorname = :v, nachname = :n WHERE id = :id'
            );
            foreach ($rows as $row) {
                $vorname = trim((string) ($row['vorname'] ?? ''));
                $nachname = trim((string) ($row['nachname'] ?? ''));
                if ($vorname !== '' || $nachname !== '') {
                    continue;
                }
                [$vorname, $nachname] = self::splitFullName((string) ($row['name'] ?? ''));
                $upd->execute([
                    'v' => $vorname,
                    'n' => $nachname,
                    'id' => (int) $row['id'],
                ]);
            }
            $pdo->exec('ALTER TABLE anmeldungen DROP COLUMN name');
        }

        $idx = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'anmeldungen'
               AND INDEX_NAME = 'idx_anmeldungen_nachname'"
        )->fetchColumn();
        if ((int) $idx === 0) {
            $pdo->exec('CREATE INDEX idx_anmeldungen_nachname ON anmeldungen (nachname, vorname)');
        }
    }

    /** @return array{0:string,1:string} */
    public static function splitFullName(string $full): array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full) ?? '');
        if ($full === '') {
            return ['', ''];
        }
        $pos = strpos($full, ' ');
        if ($pos === false) {
            return ['', $full];
        }
        return [trim(substr($full, 0, $pos)), trim(substr($full, $pos + 1))];
    }

    private function ensureZuordnungAlt(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'anmeldungen')) {
            return;
        }
        for ($i = 1; $i <= HalbjahrService::SLOT_COUNT; $i++) {
            $col = 'hj' . $i . '_verwendet_alt';
            if ($this->columnExists($pdo, 'anmeldungen', $col)) {
                continue;
            }
            $after = 'hj' . $i . '_alt_id';
            if (!$this->columnExists($pdo, 'anmeldungen', $after)) {
                continue;
            }
            $pdo->exec(
                "ALTER TABLE anmeldungen
                 ADD COLUMN `{$col}` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$after}`"
            );
        }
    }

    private function ensureJahrgaengeTabelle(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'jahrgaenge')) {
            $pdo->exec(
                'CREATE TABLE jahrgaenge (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(16) NOT NULL UNIQUE,
                    registration_start DATETIME NULL,
                    registration_end DATETIME NULL,
                    ist_aktuell TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_jahrgaenge_aktuell (ist_aktuell)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $names = [];
        if ($this->tableExists($pdo, 'settings')) {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'aktueller_jahrgang'");
            $stmt->execute();
            $configured = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($configured !== '') {
                $names[$configured] = true;
            }
        }
        if ($this->tableExists($pdo, 'anmeldungen') && $this->columnExists($pdo, 'anmeldungen', 'jahrgang')) {
            foreach ($pdo->query(
                "SELECT DISTINCT jahrgang FROM anmeldungen WHERE jahrgang <> ''"
            )->fetchAll(PDO::FETCH_COLUMN) as $jg) {
                $names[(string) $jg] = true;
            }
        }
        if ($names === []) {
            $names[AnmeldungService::defaultSchoolYear()] = true;
        }

        $start = null;
        $end = null;
        if ($this->tableExists($pdo, 'settings')) {
            $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'registration_start'");
            $s->execute();
            $startVal = trim((string) ($s->fetchColumn() ?: ''));
            $e = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'registration_end'");
            $e->execute();
            $endVal = trim((string) ($e->fetchColumn() ?: ''));
            $start = $startVal !== '' ? $startVal : null;
            $end = $endVal !== '' ? $endVal : null;
        }

        $aktuellName = array_key_first($names) ?: AnmeldungService::defaultSchoolYear();
        if ($this->tableExists($pdo, 'settings')) {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'aktueller_jahrgang'");
            $stmt->execute();
            $configured = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($configured !== '') {
                $aktuellName = $configured;
            }
        }

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO jahrgaenge (name, registration_start, registration_end, ist_aktuell)
             VALUES (:n, :s, :e, :a)'
        );
        foreach (array_keys($names) as $name) {
            $ins->execute([
                'n' => $name,
                's' => $name === $aktuellName ? $start : null,
                'e' => $name === $aktuellName ? $end : null,
                'a' => $name === $aktuellName ? 1 : 0,
            ]);
        }

        $hasAktuell = (int) $pdo->query(
            'SELECT COUNT(*) FROM jahrgaenge WHERE ist_aktuell = 1'
        )->fetchColumn();
        if ($hasAktuell === 0) {
            $upd = $pdo->prepare('UPDATE jahrgaenge SET ist_aktuell = 1 WHERE name = :n');
            $upd->execute(['n' => $aktuellName]);
            if ((int) $pdo->query('SELECT COUNT(*) FROM jahrgaenge WHERE ist_aktuell = 1')->fetchColumn() === 0) {
                $pdo->exec('UPDATE jahrgaenge SET ist_aktuell = 1 ORDER BY name DESC LIMIT 1');
            }
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