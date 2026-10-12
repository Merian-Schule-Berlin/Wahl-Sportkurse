<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class AnmeldungService
{
    public const SORT_NAME = 'nachname';
    public const SORT_VORNAME = 'vorname';
    public const SORT_NACHNAME = 'nachname';
    public const SORT_TUTORIUM = 'tutorium';
    public const SORT_EMAIL = 'email';
    public const SORT_HJ1 = 'hj1';
    public const SORT_HJ2 = 'hj2';
    public const SORT_HJ3 = 'hj3';
    public const SORT_HJ4 = 'hj4';
    public const SORT_DATE = 'created_at';

    /** @param array<string, mixed> $row */
    public static function fullName(array $row): string
    {
        return trim((string) ($row['vorname'] ?? '') . ' ' . (string) ($row['nachname'] ?? ''));
    }

    /**
     * @param array<string, mixed> $appSettings
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsService $settings,
        private readonly MailService $mail,
        private readonly HtmlContentService $html,
        private readonly array $appSettings,
        private readonly JahrgangService $jahrgaenge
    ) {
    }

    /** Schuljahr z. B. 2025/26 (ab August neues Jahr). */
    public static function defaultSchoolYear(?\DateTimeInterface $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        if ($month >= 8) {
            return self::formatSchoolYear($year, $year + 1) ?? ($year . '/' . substr((string) ($year + 1), -2));
        }
        return self::formatSchoolYear($year - 1, $year) ?? (($year - 1) . '/' . substr((string) $year, -2));
    }

    /**
     * Zwei Kalenderjahre → Anzeigeformat JJJJ/JJ (z. B. 2027 + 2028 → 2027/28).
     */
    public static function formatSchoolYear(int $von, int $bis): ?string
    {
        if ($von < 2000 || $von > 2100 || $bis < 2000 || $bis > 2100) {
            return null;
        }
        if ($bis !== $von + 1) {
            return null;
        }
        return $von . '/' . substr((string) $bis, -2);
    }

    /** @return array{0:int,1:int} Start- und Endjahr (vierstellig) */
    public static function splitSchoolYear(string $jahrgang): array
    {
        if (preg_match('/^(\d{4})\/(\d{2})$/', trim($jahrgang), $m) === 1) {
            $von = (int) $m[1];
            $short = (int) $m[2];
            $bis = intdiv($von, 100) * 100 + $short;
            if ($bis <= $von) {
                $bis += 100;
            }
            return [$von, $bis];
        }
        $fallback = self::defaultSchoolYear();
        if (preg_match('/^(\d{4})\/(\d{2})$/', $fallback, $m) === 1) {
            $von = (int) $m[1];
            return [$von, $von + 1];
        }
        $y = (int) (new \DateTimeImmutable('now'))->format('Y');
        return [$y, $y + 1];
    }

    public function resolveJahrgang(): string
    {
        return $this->jahrgaenge->resolveName();
    }

    /** @return list<string> */
    public function listJahrgaenge(): array
    {
        $list = $this->jahrgaenge->allNames();
        $fromAnmeldungen = $this->pdo->query(
            "SELECT DISTINCT jahrgang FROM anmeldungen
             WHERE jahrgang <> ''
             ORDER BY jahrgang DESC"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fromAnmeldungen as $jg) {
            $name = (string) $jg;
            if ($name !== '' && !in_array($name, $list, true)) {
                $list[] = $name;
            }
        }
        rsort($list);
        $current = $this->resolveJahrgang();
        if ($current !== '' && !in_array($current, $list, true)) {
            array_unshift($list, $current);
        }
        return array_values($list);
    }

    public function isRegistrationOpen(): bool
    {
        return $this->jahrgaenge->isRegistrationOpen();
    }

    /**
     * @param array{
     *     vorname:string,
     *     nachname:string,
     *     tutorium:string,
     *     email:string,
     *     hj1_kurs_id:int,
     *     hj1_alt_id:int,
     *     hj2_kurs_id:int,
     *     hj2_alt_id:int,
     *     hj3_kurs_id:int,
     *     hj3_alt_id:int,
     *     hj4_kurs_id:int,
     *     hj4_alt_id:int
     * } $data
     * @return array<string, mixed>
     */
    public function register(array $data, bool $allowWhenClosed = false): array
    {
        if (!$allowWhenClosed && !$this->isRegistrationOpen()) {
            throw new RuntimeException('Die Anmeldung ist derzeit geschlossen.');
        }

        $vorname = trim($data['vorname']);
        $nachname = trim($data['nachname']);
        $tutorium = trim($data['tutorium']);
        $email = trim($data['email']);

        if ($vorname === '' || $nachname === '' || $tutorium === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Bitte Vorname, Nachname, Tutorium und gültige E-Mail-Adresse angeben.');
        }

        $choices = [];
        for ($i = 1; $i <= HalbjahrService::SLOT_COUNT; $i++) {
            $kursId = (int) $data['hj' . $i . '_kurs_id'];
            $altId = (int) $data['hj' . $i . '_alt_id'];
            if ($kursId < 1 || $altId < 1) {
                throw new RuntimeException('Bitte für jedes Halbjahr eine 1. Wahl und eine Alternative wählen.');
            }
            if ($kursId === $altId) {
                throw new RuntimeException('1. Wahl und Alternative müssen im Halbjahr ' . $i . ' unterschiedlich sein.');
            }
            $choices[$i] = ['kurs' => $kursId, 'alt' => $altId];
        }

        $this->pdo->beginTransaction();
        try {
            $primaryIds = array_map(static fn (array $c): int => $c['kurs'], $choices);
            $locked = $this->lockKurse(array_values(array_unique(array_merge(
                $primaryIds,
                array_map(static fn (array $c): int => $c['alt'], $choices)
            ))));

            for ($i = 1; $i <= HalbjahrService::SLOT_COUNT; $i++) {
                $kurs = $locked[$choices[$i]['kurs']] ?? null;
                $alt = $locked[$choices[$i]['alt']] ?? null;
                if ($kurs === null || $alt === null) {
                    throw new RuntimeException('Ein gewählter Kurs ist nicht verfügbar.');
                }
                if (!$this->isChoiceAvailable($choices[$i]['kurs'], $i)
                    || !$this->isChoiceAvailable($choices[$i]['alt'], $i)) {
                    throw new RuntimeException('Ein gewählter Kurs ist in diesem Halbjahr nicht wählbar.');
                }
            }

            $this->assertWahlregeln($choices, $locked);

            $token = bin2hex(random_bytes(32));
            $jahrgang = $this->resolveJahrgang();
            $insert = $this->pdo->prepare(
                'INSERT INTO anmeldungen (
                    vorname, nachname, tutorium, email, jahrgang,
                    hj1_kurs_id, hj1_alt_id, hj2_kurs_id, hj2_alt_id,
                    hj3_kurs_id, hj3_alt_id, hj4_kurs_id, hj4_alt_id,
                    token, status
                 ) VALUES (
                    :vorname, :nachname, :tutorium, :email, :jahrgang,
                    :hj1k, :hj1a, :hj2k, :hj2a,
                    :hj3k, :hj3a, :hj4k, :hj4a,
                    :token, \'aktiv\'
                 )'
            );
            $insert->execute([
                'vorname' => $vorname,
                'nachname' => $nachname,
                'tutorium' => $tutorium,
                'email' => $email,
                'jahrgang' => $jahrgang,
                'hj1k' => $choices[1]['kurs'],
                'hj1a' => $choices[1]['alt'],
                'hj2k' => $choices[2]['kurs'],
                'hj2a' => $choices[2]['alt'],
                'hj3k' => $choices[3]['kurs'],
                'hj3a' => $choices[3]['alt'],
                'hj4k' => $choices[4]['kurs'],
                'hj4a' => $choices[4]['alt'],
                'token' => $token,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $anmeldung = $this->findByToken($token);
        if ($anmeldung === null) {
            throw new RuntimeException('Anmeldung konnte nicht geladen werden.');
        }

        $this->sendConfirmationMail($anmeldung);
        return $anmeldung;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function lockKurse(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT k.* FROM sportkurse k WHERE k.id IN ($placeholders) FOR UPDATE"
        );
        $stmt->execute(array_values($ids));
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private function isChoiceAvailable(int $kursId, int $halbjahrId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT kh.verfuegbar, h.aktiv AS hj_aktiv
             FROM kurs_halbjahr kh
             INNER JOIN halbjahre h ON h.id = kh.halbjahr_id
             WHERE kh.kurs_id = ? AND kh.halbjahr_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$kursId, $halbjahrId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        return (int) $row['verfuegbar'] === 1 && (int) $row['hj_aktiv'] === 1;
    }

    /**
     * Regeln aus dem Wahlbogen:
     * - mind. zwei verschiedene Bewegungsfelder in der 1. Wahl
     * - jedes Kursthema höchstens zweimal als 1. Wahl
     *
     * @param array<int, array{kurs:int,alt:int}> $choices
     * @param array<int, array<string, mixed>> $locked
     */
    private function assertWahlregeln(array $choices, array $locked): void
    {
        $primaryIds = [];
        $felder = [];
        foreach ($choices as $choice) {
            $kursId = (int) $choice['kurs'];
            $kurs = $locked[$kursId] ?? null;
            if ($kurs === null) {
                throw new RuntimeException('Ein gewählter Kurs ist nicht verfügbar.');
            }
            $primaryIds[] = $kursId;
            $feld = trim((string) ($kurs['bewegungsfeld'] ?? ''));
            $key = $feld !== '' ? mb_strtolower($feld) : ('kurs:' . $kursId);
            $felder[$key] = true;
        }

        if (count($felder) < 2) {
            throw new RuntimeException(
                'Über die vier Halbjahre müssen in der 1. Wahl Sportkurse aus mindestens zwei verschiedenen Bewegungsfeldern gewählt werden.'
            );
        }

        $counts = array_count_values($primaryIds);
        foreach ($counts as $kursId => $count) {
            if ($count > 2) {
                $name = (string) ($locked[(int) $kursId]['name'] ?? 'Kurs');
                throw new RuntimeException(
                    'Das Kursthema „' . $name . '“ darf in den vier Halbjahren höchstens zweimal als 1. Wahl gewählt werden.'
                );
            }
        }
    }

    /**
     * Teilnehmer je zugewiesenem Kurs (1. Wahl oder Alternative), gruppiert nach Halbjahr.
     *
     * @param list<int>|null $halbjahre
     * @return list<array{
     *   halbjahr_id:int,
     *   halbjahr_name:string,
     *   kurs_id:int,
     *   kurs_name:string,
     *   teilnehmer:list<array{
     *     id:int,
     *     vorname:string,
     *     nachname:string,
     *     name:string,
     *     tutorium:string,
     *     email:string,
     *     verwendet_alt:bool,
     *     wahl_kurs_id:int,
     *     wahl_kurs_name:string,
     *     alt_kurs_id:int,
     *     alt_kurs_name:string
     *   }>
     * }>
     */
    public function teilnehmerNachKurs(
        ?string $jahrgang = null,
        string $search = '',
        ?array $halbjahre = null
    ): array {
        $jahrgang = $jahrgang ?? $this->resolveJahrgang();
        $hjIds = $this->normalizeHalbjahre($halbjahre);

        $hjNames = [];
        foreach ($this->pdo->query('SELECT id, name FROM halbjahre ORDER BY id ASC')->fetchAll() as $hj) {
            $hjNames[(int) $hj['id']] = (string) $hj['name'];
        }

        $kursMeta = [];
        foreach ($this->pdo->query(
            'SELECT id, name, sortierung FROM sportkurse ORDER BY sortierung ASC, name ASC'
        )->fetchAll() as $k) {
            $kursMeta[(int) $k['id']] = [
                'name' => (string) $k['name'],
                'sortierung' => (int) $k['sortierung'],
            ];
        }

        [$searchSql, $searchParams] = $this->searchClause($search);
        $stmt = $this->pdo->prepare(
            "SELECT a.*
             FROM anmeldungen a
             WHERE a.status = 'aktiv' AND a.jahrgang = :j{$searchSql}
             ORDER BY a.nachname ASC, a.vorname ASC"
        );
        $stmt->execute(array_merge(['j' => $jahrgang], $searchParams));
        $anmeldungen = $stmt->fetchAll();

        $out = [];
        foreach ($hjIds as $hj) {
            $byKurs = [];
            foreach ($anmeldungen as $row) {
                $wahlId = (int) $row['hj' . $hj . '_kurs_id'];
                $altId = (int) $row['hj' . $hj . '_alt_id'];
                $useAlt = (int) ($row['hj' . $hj . '_verwendet_alt'] ?? 0) === 1;
                $kursId = $useAlt ? $altId : $wahlId;
                if ($kursId < 1) {
                    continue;
                }
                if (!isset($byKurs[$kursId])) {
                    $byKurs[$kursId] = [
                        'halbjahr_id' => $hj,
                        'halbjahr_name' => $hjNames[$hj] ?? ('Halbjahr ' . $hj),
                        'kurs_id' => $kursId,
                        'kurs_name' => $kursMeta[$kursId]['name'] ?? ('Kurs #' . $kursId),
                        'kurs_sort' => $kursMeta[$kursId]['sortierung'] ?? 9999,
                        'teilnehmer' => [],
                    ];
                }
                $byKurs[$kursId]['teilnehmer'][] = [
                    'id' => (int) $row['id'],
                    'vorname' => (string) $row['vorname'],
                    'nachname' => (string) $row['nachname'],
                    'name' => self::fullName($row),
                    'tutorium' => (string) $row['tutorium'],
                    'email' => (string) $row['email'],
                    'verwendet_alt' => $useAlt,
                    'wahl_kurs_id' => $wahlId,
                    'wahl_kurs_name' => $kursMeta[$wahlId]['name'] ?? '',
                    'alt_kurs_id' => $altId,
                    'alt_kurs_name' => $kursMeta[$altId]['name'] ?? '',
                ];
            }
            uasort(
                $byKurs,
                static function (array $a, array $b): int {
                    $cmp = $a['kurs_sort'] <=> $b['kurs_sort'];
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                    return strcasecmp($a['kurs_name'], $b['kurs_name']);
                }
            );
            foreach ($byKurs as $block) {
                unset($block['kurs_sort']);
                $out[] = $block;
            }
        }
        return $out;
    }

    /**
     * @param list<int|string>|null $halbjahre
     * @return list<int>
     */
    public function normalizeHalbjahre(?array $halbjahre): array
    {
        if ($halbjahre === null || $halbjahre === []) {
            return range(1, HalbjahrService::SLOT_COUNT);
        }
        $ids = [];
        foreach ($halbjahre as $id) {
            $n = (int) $id;
            if ($n >= 1 && $n <= HalbjahrService::SLOT_COUNT) {
                $ids[$n] = $n;
            }
        }
        $list = array_values($ids);
        sort($list);
        return $list !== [] ? $list : range(1, HalbjahrService::SLOT_COUNT);
    }

    public function setVerwendetAlt(int $anmeldungId, int $halbjahrId, bool $verwendetAlt): bool
    {
        if ($anmeldungId < 1 || $halbjahrId < 1 || $halbjahrId > HalbjahrService::SLOT_COUNT) {
            return false;
        }
        $col = 'hj' . $halbjahrId . '_verwendet_alt';
        $stmt = $this->pdo->prepare(
            "UPDATE anmeldungen SET `{$col}` = :v WHERE id = :id AND status = 'aktiv'"
        );
        $stmt->execute([
            'v' => $verwendetAlt ? 1 : 0,
            'id' => $anmeldungId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*,
                    k1.name AS hj1_kurs_name, a1.name AS hj1_alt_name, h1.name AS hj1_name,
                    k2.name AS hj2_kurs_name, a2.name AS hj2_alt_name, h2.name AS hj2_name,
                    k3.name AS hj3_kurs_name, a3.name AS hj3_alt_name, h3.name AS hj3_name,
                    k4.name AS hj4_kurs_name, a4.name AS hj4_alt_name, h4.name AS hj4_name
             FROM anmeldungen a
             INNER JOIN sportkurse k1 ON k1.id = a.hj1_kurs_id
             INNER JOIN sportkurse a1 ON a1.id = a.hj1_alt_id
             INNER JOIN sportkurse k2 ON k2.id = a.hj2_kurs_id
             INNER JOIN sportkurse a2 ON a2.id = a.hj2_alt_id
             INNER JOIN sportkurse k3 ON k3.id = a.hj3_kurs_id
             INNER JOIN sportkurse a3 ON a3.id = a.hj3_alt_id
             INNER JOIN sportkurse k4 ON k4.id = a.hj4_kurs_id
             INNER JOIN sportkurse a4 ON a4.id = a.hj4_alt_id
             INNER JOIN halbjahre h1 ON h1.id = 1
             INNER JOIN halbjahre h2 ON h2.id = 2
             INNER JOIN halbjahre h3 ON h3.id = 3
             INNER JOIN halbjahre h4 ON h4.id = 4
             WHERE a.token = :token'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allActive(
        ?string $jahrgang = null,
        string $sort = self::SORT_NACHNAME,
        string $dir = 'asc',
        string $search = ''
    ): array {
        $jahrgang = $jahrgang ?? $this->resolveJahrgang();
        $order = $this->orderByClause($sort, $dir);
        [$searchSql, $searchParams] = $this->searchClause($search);
        $stmt = $this->pdo->prepare(
            "SELECT a.*,
                    k1.name AS hj1_kurs_name, a1.name AS hj1_alt_name,
                    k2.name AS hj2_kurs_name, a2.name AS hj2_alt_name,
                    k3.name AS hj3_kurs_name, a3.name AS hj3_alt_name,
                    k4.name AS hj4_kurs_name, a4.name AS hj4_alt_name
             FROM anmeldungen a
             INNER JOIN sportkurse k1 ON k1.id = a.hj1_kurs_id
             INNER JOIN sportkurse a1 ON a1.id = a.hj1_alt_id
             INNER JOIN sportkurse k2 ON k2.id = a.hj2_kurs_id
             INNER JOIN sportkurse a2 ON a2.id = a.hj2_alt_id
             INNER JOIN sportkurse k3 ON k3.id = a.hj3_kurs_id
             INNER JOIN sportkurse a3 ON a3.id = a.hj3_alt_id
             INNER JOIN sportkurse k4 ON k4.id = a.hj4_kurs_id
             INNER JOIN sportkurse a4 ON a4.id = a.hj4_alt_id
             WHERE a.status = 'aktiv' AND a.jahrgang = :j{$searchSql}
             ORDER BY {$order}"
        );
        $stmt->execute(array_merge(['j' => $jahrgang], $searchParams));
        return $stmt->fetchAll();
    }

    private function orderByClause(string $sort, string $dir): string
    {
        $dirSql = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        return match ($sort) {
            self::SORT_VORNAME => "a.vorname {$dirSql}, a.nachname ASC",
            self::SORT_TUTORIUM => "a.tutorium {$dirSql}, a.nachname ASC, a.vorname ASC",
            self::SORT_EMAIL => "a.email {$dirSql}, a.nachname ASC, a.vorname ASC",
            self::SORT_HJ1 => "k1.name {$dirSql}, a.nachname ASC, a.vorname ASC",
            self::SORT_HJ2 => "k2.name {$dirSql}, a.nachname ASC, a.vorname ASC",
            self::SORT_HJ3 => "k3.name {$dirSql}, a.nachname ASC, a.vorname ASC",
            self::SORT_HJ4 => "k4.name {$dirSql}, a.nachname ASC, a.vorname ASC",
            self::SORT_DATE => "a.created_at {$dirSql}, a.nachname ASC, a.vorname ASC",
            default => "a.nachname {$dirSql}, a.vorname ASC, a.created_at DESC",
        };
    }

    /** @return array{0:string,1:array<string,string>} */
    private function searchClause(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['', []];
        }
        return [
            ' AND (
                a.vorname LIKE :q
                OR a.nachname LIKE :q2
                OR a.email LIKE :q3
                OR a.tutorium LIKE :q4
                OR CONCAT(a.vorname, \' \', a.nachname) LIKE :q5
                OR CONCAT(a.nachname, \' \', a.vorname) LIKE :q6
            )',
            [
                'q' => '%' . $search . '%',
                'q2' => '%' . $search . '%',
                'q3' => '%' . $search . '%',
                'q4' => '%' . $search . '%',
                'q5' => '%' . $search . '%',
                'q6' => '%' . $search . '%',
            ],
        ];
    }

    public function cancel(string $token): void
    {
        $anmeldung = $this->findByToken($token);
        if ($anmeldung === null) {
            throw new RuntimeException('Anmeldung nicht gefunden.');
        }
        if ($anmeldung['status'] === 'storniert') {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE anmeldungen SET status = \'storniert\', cancelled_at = NOW()
             WHERE token = :token AND status = \'aktiv\''
        );
        $stmt->execute(['token' => $token]);
        if ($stmt->rowCount() > 0) {
            $this->sendCancelMail($anmeldung);
        }
    }

    public function countActive(?string $jahrgang = null, string $search = ''): int
    {
        $jahrgang = $jahrgang ?? $this->resolveJahrgang();
        [$searchSql, $searchParams] = $this->searchClause($search);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM anmeldungen a WHERE a.status = 'aktiv' AND a.jahrgang = :j{$searchSql}"
        );
        $stmt->execute(array_merge(['j' => $jahrgang], $searchParams));
        return (int) $stmt->fetchColumn();
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM anmeldungen')->fetchColumn();
    }

    public function deleteAll(): int
    {
        $count = $this->countAll();
        $this->pdo->exec('DELETE FROM anmeldungen');
        return $count;
    }

    public function deleteById(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM anmeldungen WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<int> $ids
     */
    public function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($v): int => (int) $v, $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM anmeldungen WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    /** @param array<string, mixed> $anmeldung */
    private function sendConfirmationMail(array $anmeldung): void
    {
        $this->sendTemplatedMail(
            $anmeldung,
            'mail_subject',
            (string) ($this->appSettings['mail']['subject'] ?? 'Ihre Sportkurs-Wahl'),
            'mail_body',
            self::defaultMailBody()
        );
    }

    /** @param array<string, mixed> $anmeldung */
    private function sendCancelMail(array $anmeldung): void
    {
        $this->sendTemplatedMail(
            $anmeldung,
            'mail_cancel_subject',
            self::defaultCancelMailSubject(),
            'mail_cancel_body',
            self::defaultCancelMailBody()
        );
    }

    /**
     * @param array<string, mixed> $anmeldung
     */
    private function sendTemplatedMail(
        array $anmeldung,
        string $subjectKey,
        string $subjectFallback,
        string $bodyKey,
        string $bodyFallback
    ): void {
        $baseUrl = rtrim((string) ($this->appSettings['app_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $baseUrl = $this->guessBaseUrl();
        }
        $cancelUrl = $baseUrl . '/stornieren/' . $anmeldung['token'];
        $thanksUrl = $baseUrl . '/danke/' . $anmeldung['token'];

        $from = $this->settings->get('mail_from', (string) ($this->appSettings['mail']['from'] ?? ''));
        $fromName = $this->settings->get(
            'mail_from_name',
            (string) ($this->appSettings['mail']['from_name'] ?? 'Wahl-Sportkurse')
        );

        $choicesHtml = $this->choicesSummaryHtml($anmeldung);
        $choicesText = $this->choicesSummaryText($anmeldung);

        $fullName = self::fullName($anmeldung);
        $rawPlaceholders = [
            '{name}' => $fullName,
            '{vorname}' => (string) ($anmeldung['vorname'] ?? ''),
            '{nachname}' => (string) ($anmeldung['nachname'] ?? ''),
            '{tutorium}' => (string) $anmeldung['tutorium'],
            '{email}' => (string) $anmeldung['email'],
            '{choices}' => $choicesText,
            '{cancel_url}' => $cancelUrl,
            '{thanks_url}' => $thanksUrl,
            '{from_name}' => $fromName,
            '{logo}' => '',
        ];
        $htmlPlaceholders = [];
        foreach ($rawPlaceholders as $key => $value) {
            $htmlPlaceholders[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $htmlPlaceholders['{logo}'] = $this->logoImgHtml($baseUrl, $fromName);
        $htmlPlaceholders['{choices}'] = $choicesHtml;

        $subjectTemplate = $this->settings->get($subjectKey, $subjectFallback);
        $bodyTemplate = trim($this->settings->get($bodyKey));
        if ($bodyTemplate === '') {
            $bodyTemplate = $bodyFallback;
        }

        $subject = strtr($subjectTemplate, $rawPlaceholders);
        $bodyHtml = strtr($this->html->toSafeHtml($bodyTemplate), $htmlPlaceholders);

        if ($from !== '') {
            $this->mail->send((string) $anmeldung['email'], $subject, $bodyHtml, $from, $fromName, true);
        }
    }

    /** @param array<string, mixed> $anmeldung */
    private function choicesSummaryText(array $anmeldung): string
    {
        $lines = [];
        for ($i = 1; $i <= HalbjahrService::SLOT_COUNT; $i++) {
            $hj = (string) ($anmeldung['hj' . $i . '_name'] ?? ('Halbjahr ' . $i));
            $kurs = (string) ($anmeldung['hj' . $i . '_kurs_name'] ?? '');
            $alt = (string) ($anmeldung['hj' . $i . '_alt_name'] ?? '');
            $lines[] = $hj . ': ' . $kurs . ' (Alternative: ' . $alt . ')';
        }
        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $anmeldung */
    private function choicesSummaryHtml(array $anmeldung): string
    {
        $rows = '';
        for ($i = 1; $i <= HalbjahrService::SLOT_COUNT; $i++) {
            $hj = htmlspecialchars((string) ($anmeldung['hj' . $i . '_name'] ?? ('Halbjahr ' . $i)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $kurs = htmlspecialchars((string) ($anmeldung['hj' . $i . '_kurs_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $alt = htmlspecialchars((string) ($anmeldung['hj' . $i . '_alt_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $rows .= '<tr><td>' . $hj . '</td><td>' . $kurs . '</td><td>' . $alt . '</td></tr>';
        }
        return '<table style="border-collapse:collapse;width:100%;max-width:480px">'
            . '<thead><tr><th align="left">Halbjahr</th><th align="left">1. Wahl</th><th align="left">Alternative</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
    }

    public static function defaultMailBody(): string
    {
        return '<p>{logo}</p>'
            . '<p>Hallo {vorname},</p>'
            . '<p>vielen Dank für Ihre Sportkurs-Wahl.</p>'
            . '<p><strong>Ihre Angaben:</strong><br>'
            . 'Name: {vorname} {nachname}<br>'
            . 'Tutorium: {tutorium}<br>'
            . 'E-Mail: {email}</p>'
            . '<p><strong>Ihre Wahl:</strong></p>'
            . '{choices}'
            . '<p>Sollten Sie stornieren wollen, nutzen Sie bitte folgenden Link:<br>'
            . '<a href="{cancel_url}">{cancel_url}</a></p>'
            . '<p>Mit freundlichen Grüßen<br>{from_name}</p>'
            . '<p>Webansicht:<br><a href="{thanks_url}">{thanks_url}</a></p>';
    }

    public static function defaultCancelMailBody(): string
    {
        return '<p>{logo}</p>'
            . '<p>Hallo {vorname},</p>'
            . '<p>Ihre Sportkurs-Wahl wurde storniert.</p>'
            . '<p><strong>Stornierte Wahl:</strong></p>'
            . '{choices}'
            . '<p>Mit freundlichen Grüßen<br>{from_name}</p>';
    }

    public static function defaultCancelMailSubject(): string
    {
        return 'Stornierung Ihrer Sportkurs-Wahl';
    }

    private function logoImgHtml(string $baseUrl, string $alt): string
    {
        $logo = trim($this->settings->get('site_logo'));
        if ($logo === '' || $baseUrl === '') {
            return '';
        }

        $filename = basename(str_replace('\\', '/', $logo));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return '';
        }

        $src = $baseUrl . '/uploads/' . rawurlencode($filename);
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' style="display:block;border:0;max-width:180px;height:auto;" />';
    }

    private function guessBaseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}
