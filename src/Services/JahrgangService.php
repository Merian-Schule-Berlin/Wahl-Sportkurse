<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class JahrgangService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT * FROM jahrgaenge ORDER BY name DESC'
        )->fetchAll();
    }

    /** @return list<string> */
    public function allNames(): array
    {
        $rows = $this->pdo->query(
            "SELECT name FROM jahrgaenge WHERE name <> '' ORDER BY name DESC"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map('strval', $rows));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jahrgaenge WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jahrgaenge WHERE name = :n');
        $stmt->execute(['n' => $name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function getAktuell(): ?array
    {
        $row = $this->pdo->query(
            'SELECT * FROM jahrgaenge WHERE ist_aktuell = 1 ORDER BY id DESC LIMIT 1'
        )->fetch();
        if ($row) {
            return $row;
        }
        $row = $this->pdo->query(
            'SELECT * FROM jahrgaenge ORDER BY name DESC LIMIT 1'
        )->fetch();
        return $row ?: null;
    }

    public function resolveName(): string
    {
        $aktuell = $this->getAktuell();
        if ($aktuell !== null) {
            return (string) $aktuell['name'];
        }
        return AnmeldungService::defaultSchoolYear();
    }

    public function create(int $von, int $bis, ?string $start = null, ?string $end = null, bool $alsAktuell = false): string
    {
        $name = AnmeldungService::formatSchoolYear($von, $bis);
        if ($name === null) {
            throw new RuntimeException('Bitte zwei aufeinanderfolgende Jahre angeben (z. B. 2027 und 2028 → 2027/28).');
        }
        if ($this->findByName($name) !== null) {
            throw new RuntimeException('Der Jahrgang ' . $name . ' existiert bereits.');
        }

        $startNorm = $this->normalizeDateTime($start);
        $endNorm = $this->normalizeDateTime($end);

        $stmt = $this->pdo->prepare(
            'INSERT INTO jahrgaenge (name, registration_start, registration_end, ist_aktuell)
             VALUES (:n, :s, :e, 0)'
        );
        $stmt->execute([
            'n' => $name,
            's' => $startNorm,
            'e' => $endNorm,
        ]);

        if ($alsAktuell || $this->count() === 1) {
            $this->setAktuellByName($name);
        }

        return $name;
    }

    public function updateTimes(int $id, ?string $start, ?string $end): void
    {
        if ($this->find($id) === null) {
            throw new RuntimeException('Jahrgang nicht gefunden.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE jahrgaenge
             SET registration_start = :s, registration_end = :e
             WHERE id = :id'
        );
        $stmt->execute([
            's' => $this->normalizeDateTime($start),
            'e' => $this->normalizeDateTime($end),
            'id' => $id,
        ]);
    }

    public function setAktuell(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Jahrgang nicht gefunden.');
        }
        $this->pdo->exec('UPDATE jahrgaenge SET ist_aktuell = 0');
        $stmt = $this->pdo->prepare('UPDATE jahrgaenge SET ist_aktuell = 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function setAktuellByName(string $name): void
    {
        $row = $this->findByName($name);
        if ($row === null) {
            throw new RuntimeException('Jahrgang nicht gefunden.');
        }
        $this->setAktuell((int) $row['id']);
    }

    public function delete(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new RuntimeException('Jahrgang nicht gefunden.');
        }
        $name = (string) $row['name'];
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM anmeldungen WHERE jahrgang = :n');
        $stmt->execute(['n' => $name]);
        $used = (int) $stmt->fetchColumn();
        if ($used > 0) {
            throw new RuntimeException(
                'Jahrgang ' . $name . ' kann nicht gelöscht werden – es gibt noch ' . $used . ' Anmeldung(en).'
            );
        }
        $wasAktuell = (int) ($row['ist_aktuell'] ?? 0) === 1;
        $del = $this->pdo->prepare('DELETE FROM jahrgaenge WHERE id = :id');
        $del->execute(['id' => $id]);
        if ($wasAktuell) {
            $next = $this->getAktuell();
            if ($next !== null) {
                $this->setAktuell((int) $next['id']);
            }
        }
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM jahrgaenge')->fetchColumn();
    }

    public function isRegistrationOpen(?array $jahrgang = null): bool
    {
        $jg = $jahrgang ?? $this->getAktuell();
        $start = is_array($jg) ? trim((string) ($jg['registration_start'] ?? '')) : '';
        $end = is_array($jg) ? trim((string) ($jg['registration_end'] ?? '')) : '';
        $now = time();

        if ($start !== '') {
            $startTs = strtotime($start);
            if ($startTs !== false && $now < $startTs) {
                return false;
            }
        }
        if ($end !== '') {
            $endTs = strtotime($end);
            if ($endTs !== false && $now > $endTs) {
                return false;
            }
        }
        return true;
    }

    private function normalizeDateTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new RuntimeException('Ungültiges Datum/Uhrzeit.');
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
