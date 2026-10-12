<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class HalbjahrService
{
    public const SLOT_COUNT = 4;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM halbjahre';
        if ($onlyActive) {
            $sql .= ' WHERE aktiv = 1';
        }
        $sql .= ' ORDER BY sortierung ASC, id ASC';
        return $this->pdo->query($sql)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM halbjahre WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param array{name:string,aktiv:int,sortierung:int} $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE halbjahre SET name = :name, aktiv = :aktiv, sortierung = :sortierung WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'aktiv' => $data['aktiv'],
            'sortierung' => $data['sortierung'],
        ]);
    }

    public function toggle(int $id): void
    {
        $this->pdo->prepare('UPDATE halbjahre SET aktiv = IF(aktiv = 1, 0, 1) WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function kurseFor(int $halbjahrId, bool $onlyActive = false): array
    {
        $sql = 'SELECT k.*,
                       (
                           SELECT COUNT(*) FROM anmeldungen a
                           WHERE a.status = \'aktiv\'
                             AND (
                                 a.hj1_kurs_id = k.id OR a.hj2_kurs_id = k.id
                                 OR a.hj3_kurs_id = k.id OR a.hj4_kurs_id = k.id
                             )
                       ) AS belegt
                FROM sportkurse k
                WHERE k.halbjahr_id = :id';
        if ($onlyActive) {
            $sql .= ' AND k.aktiv = 1';
        }
        $sql .= ' ORDER BY k.sortierung ASC, k.name ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $halbjahrId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findKurs(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT k.*, h.name AS halbjahr_name, h.aktiv AS halbjahr_aktiv,
                    (
                        SELECT COUNT(*) FROM anmeldungen a
                        WHERE a.status = \'aktiv\'
                          AND (
                              a.hj1_kurs_id = k.id OR a.hj2_kurs_id = k.id
                              OR a.hj3_kurs_id = k.id OR a.hj4_kurs_id = k.id
                          )
                    ) AS belegt
             FROM sportkurse k
             INNER JOIN halbjahre h ON h.id = k.halbjahr_id
             WHERE k.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param array{halbjahr_id:int,name:string,kapazitaet:int,aktiv:int,sortierung?:int} $data
     */
    public function createKurs(array $data): int
    {
        if (!isset($data['sortierung'])) {
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(sortierung), -1) FROM sportkurse WHERE halbjahr_id = :id'
            );
            $stmt->execute(['id' => $data['halbjahr_id']]);
            $data['sortierung'] = (int) $stmt->fetchColumn() + 1;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO sportkurse (halbjahr_id, name, kapazitaet, aktiv, sortierung)
             VALUES (:halbjahr_id, :name, :kapazitaet, :aktiv, :sortierung)'
        );
        $stmt->execute([
            'halbjahr_id' => $data['halbjahr_id'],
            'name' => $data['name'],
            'kapazitaet' => $data['kapazitaet'],
            'aktiv' => $data['aktiv'],
            'sortierung' => $data['sortierung'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{name:string,kapazitaet:int,aktiv:int,sortierung:int} $data
     */
    public function updateKurs(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sportkurse
             SET name = :name, kapazitaet = :kapazitaet, aktiv = :aktiv, sortierung = :sortierung
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'kapazitaet' => $data['kapazitaet'],
            'aktiv' => $data['aktiv'],
            'sortierung' => $data['sortierung'],
        ]);
    }

    public function deleteKurs(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM anmeldungen
             WHERE hj1_kurs_id = :id OR hj1_alt_id = :id
                OR hj2_kurs_id = :id OR hj2_alt_id = :id
                OR hj3_kurs_id = :id OR hj3_alt_id = :id
                OR hj4_kurs_id = :id OR hj4_alt_id = :id'
        );
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('Kurs kann nicht gelöscht werden – es gibt noch Anmeldungen.');
        }
        $this->pdo->prepare('DELETE FROM sportkurse WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Active Halbjahre with enriched course capacity for the public form.
     *
     * @return list<array<string, mixed>>
     */
    public function activeWithKurse(): array
    {
        $list = [];
        foreach ($this->all(true) as $hj) {
            // Alle Kurse laden – inaktive erscheinen grau/gesperrt im Formular
            $kurse = $this->kurseFor((int) $hj['id'], false);
            foreach ($kurse as &$kurs) {
                $kap = (int) $kurs['kapazitaet'];
                $belegt = (int) ($kurs['belegt'] ?? 0);
                $kurs['frei'] = max(0, $kap - $belegt);
            }
            unset($kurs);
            $hj['kurse'] = $kurse;
            $list[] = $hj;
        }
        return $list;
    }

    /**
     * Halbjahre + Kurse with stats for admin dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public function withStats(): array
    {
        $list = [];
        foreach ($this->all() as $hj) {
            $kurse = $this->kurseFor((int) $hj['id']);
            foreach ($kurse as &$kurs) {
                $kap = (int) $kurs['kapazitaet'];
                $belegt = (int) ($kurs['belegt'] ?? 0);
                $kurs['frei'] = max(0, $kap - $belegt);
            }
            unset($kurs);
            $hj['kurse'] = $kurse;
            $list[] = $hj;
        }
        return $list;
    }

    public function occupiedForKurs(int $kursId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM anmeldungen
             WHERE status = \'aktiv\'
               AND (
                   hj1_kurs_id = :id OR hj2_kurs_id = :id
                   OR hj3_kurs_id = :id OR hj4_kurs_id = :id
               )'
        );
        $stmt->execute(['id' => $kursId]);
        return (int) $stmt->fetchColumn();
    }
}
