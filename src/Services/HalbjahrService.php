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
    public function allKurse(): array
    {
        return $this->pdo->query(
            'SELECT k.*,
                    (
                        SELECT COUNT(*) FROM anmeldungen a
                        WHERE a.status = \'aktiv\'
                          AND (
                              a.hj1_kurs_id = k.id OR a.hj1_alt_id = k.id
                              OR a.hj2_kurs_id = k.id OR a.hj2_alt_id = k.id
                              OR a.hj3_kurs_id = k.id OR a.hj3_alt_id = k.id
                              OR a.hj4_kurs_id = k.id OR a.hj4_alt_id = k.id
                          )
                    ) AS teilnehmer
             FROM sportkurse k
             ORDER BY k.sortierung ASC, k.name ASC'
        )->fetchAll();
    }

    /**
     * Kurse für ein Halbjahr inkl. Verfügbarkeit.
     * Feld "aktiv" entspricht verfuegbar (für bestehende Templates).
     *
     * @return list<array<string, mixed>>
     */
    public function kurseFor(int $halbjahrId, bool $onlyAvailable = false): array
    {
        $sql = 'SELECT k.*,
                       kh.verfuegbar,
                       kh.verfuegbar AS aktiv,
                       (
                           SELECT COUNT(*) FROM anmeldungen a
                           WHERE a.status = \'aktiv\'
                             AND a.hj' . (int) $halbjahrId . '_kurs_id = k.id
                       ) AS teilnehmer
                FROM sportkurse k
                INNER JOIN kurs_halbjahr kh ON kh.kurs_id = k.id AND kh.halbjahr_id = :id';
        if ($onlyAvailable) {
            $sql .= ' AND kh.verfuegbar = 1';
        }
        $sql .= ' ORDER BY k.sortierung ASC, k.name ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $halbjahrId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findKurs(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sportkurse WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function isKursVerfuegbar(int $kursId, int $halbjahrId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT kh.verfuegbar, h.aktiv AS hj_aktiv
             FROM kurs_halbjahr kh
             INNER JOIN halbjahre h ON h.id = kh.halbjahr_id
             WHERE kh.kurs_id = :kid AND kh.halbjahr_id = :hid'
        );
        $stmt->execute(['kid' => $kursId, 'hid' => $halbjahrId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        return (int) $row['verfuegbar'] === 1 && (int) $row['hj_aktiv'] === 1;
    }

    /**
     * @param array{name:string,bewegungsfeld?:string,sortierung?:int} $data
     */
    public function createKurs(array $data): int
    {
        if (!isset($data['sortierung'])) {
            $data['sortierung'] = (int) $this->pdo->query(
                'SELECT COALESCE(MAX(sortierung), -1) FROM sportkurse'
            )->fetchColumn() + 1;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO sportkurse (name, bewegungsfeld, kapazitaet, aktiv, sortierung)
                 VALUES (:name, :bewegungsfeld, 0, 1, :sortierung)'
            );
            $stmt->execute([
                'name' => $data['name'],
                'bewegungsfeld' => trim((string) ($data['bewegungsfeld'] ?? '')),
                'sortierung' => $data['sortierung'],
            ]);
            $id = (int) $this->pdo->lastInsertId();

            $ins = $this->pdo->prepare(
                'INSERT INTO kurs_halbjahr (kurs_id, halbjahr_id, verfuegbar) VALUES (?, ?, 1)'
            );
            foreach ($this->all() as $hj) {
                $ins->execute([$id, (int) $hj['id']]);
            }
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array{name:string,bewegungsfeld?:string,sortierung:int} $data
     */
    public function updateKurs(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sportkurse
             SET name = :name, bewegungsfeld = :bewegungsfeld, sortierung = :sortierung
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'bewegungsfeld' => trim((string) ($data['bewegungsfeld'] ?? '')),
            'sortierung' => $data['sortierung'],
        ]);
    }

    public function setKursVerfuegbar(int $kursId, int $halbjahrId, bool $verfuegbar): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO kurs_halbjahr (kurs_id, halbjahr_id, verfuegbar)
             VALUES (:kid, :hid, :v)
             ON DUPLICATE KEY UPDATE verfuegbar = VALUES(verfuegbar)'
        );
        $stmt->execute([
            'kid' => $kursId,
            'hid' => $halbjahrId,
            'v' => $verfuegbar ? 1 : 0,
        ]);
    }

    public function deleteKurs(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM anmeldungen
             WHERE hj1_kurs_id = ? OR hj1_alt_id = ?
                OR hj2_kurs_id = ? OR hj2_alt_id = ?
                OR hj3_kurs_id = ? OR hj3_alt_id = ?
                OR hj4_kurs_id = ? OR hj4_alt_id = ?'
        );
        $stmt->execute([$id, $id, $id, $id, $id, $id, $id, $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('Kurs kann nicht gelöscht werden – es gibt noch Anmeldungen.');
        }
        $this->pdo->prepare('DELETE FROM kurs_halbjahr WHERE kurs_id = :id')->execute(['id' => $id]);
        $this->pdo->prepare('DELETE FROM sportkurse WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeWithKurse(): array
    {
        $list = [];
        foreach ($this->all(true) as $hj) {
            $hj['kurse'] = $this->kurseFor((int) $hj['id'], false);
            $list[] = $hj;
        }
        return $list;
    }

    /**
     * Eine Kursliste für die Anmelde-Matrix (Kurse × Halbjahre).
     *
     * @return array{halbjahre: list<array<string, mixed>>, kurse: list<array<string, mixed>>}
     */
    public function registrationMatrix(): array
    {
        $halbjahre = $this->all(true);
        $kurse = $this->pdo->query(
            'SELECT id, name, bewegungsfeld, sortierung
             FROM sportkurse
             ORDER BY sortierung ASC, name ASC'
        )->fetchAll();

        $avail = [];
        foreach ($this->pdo->query('SELECT kurs_id, halbjahr_id, verfuegbar FROM kurs_halbjahr') as $row) {
            $avail[(int) $row['kurs_id']][(int) $row['halbjahr_id']] = (int) $row['verfuegbar'] === 1;
        }

        foreach ($kurse as &$kurs) {
            $map = [];
            foreach ($halbjahre as $hj) {
                $hid = (int) $hj['id'];
                $map[$hid] = $avail[(int) $kurs['id']][$hid] ?? false;
            }
            $kurs['verfuegbar'] = $map;
        }
        unset($kurs);

        return [
            'halbjahre' => $halbjahre,
            'kurse' => $kurse,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function withStats(): array
    {
        $list = [];
        foreach ($this->all() as $hj) {
            $kurse = $this->kurseFor((int) $hj['id']);
            $hj['kurse'] = $kurse;
            $hj['kurse_verfuegbar'] = count(array_filter(
                $kurse,
                static fn (array $k): bool => (int) ($k['verfuegbar'] ?? 0) === 1
            ));
            $list[] = $hj;
        }
        return $list;
    }
}