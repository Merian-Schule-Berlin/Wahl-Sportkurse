<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class AnmeldungService
{
    /**
     * @param array<string, mixed> $appSettings
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsService $settings,
        private readonly MailService $mail,
        private readonly HtmlContentService $html,
        private readonly array $appSettings
    ) {
    }

    /**
     * @param array{
     *     name:string,
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
        if (!$allowWhenClosed && !$this->settings->isRegistrationOpen()) {
            throw new RuntimeException('Die Anmeldung ist derzeit geschlossen.');
        }

        $name = trim($data['name']);
        $tutorium = trim($data['tutorium']);
        $email = trim($data['email']);

        if ($name === '' || $tutorium === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Bitte Name, Tutorium und gültige E-Mail-Adresse angeben.');
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
                if (!(int) $kurs['aktiv'] || !(int) $kurs['halbjahr_aktiv']
                    || !(int) $alt['aktiv'] || !(int) $alt['halbjahr_aktiv']) {
                    throw new RuntimeException('Ein gewählter Kurs ist nicht aktiv.');
                }
                if ((int) $kurs['halbjahr_id'] !== $i || (int) $alt['halbjahr_id'] !== $i) {
                    throw new RuntimeException('Kurse müssen zum jeweiligen Halbjahr gehören.');
                }

                $belegt = $this->countPrimaryLocked((int) $kurs['id']);
                if ($belegt >= (int) $kurs['kapazitaet']) {
                    throw new RuntimeException(
                        'Der Kurs „' . $kurs['name'] . '“ in ' . $kurs['halbjahr_name'] . ' ist ausgebucht.'
                    );
                }
            }

            $token = bin2hex(random_bytes(32));
            $insert = $this->pdo->prepare(
                'INSERT INTO anmeldungen (
                    name, tutorium, email,
                    hj1_kurs_id, hj1_alt_id, hj2_kurs_id, hj2_alt_id,
                    hj3_kurs_id, hj3_alt_id, hj4_kurs_id, hj4_alt_id,
                    token, status
                 ) VALUES (
                    :name, :tutorium, :email,
                    :hj1k, :hj1a, :hj2k, :hj2a,
                    :hj3k, :hj3a, :hj4k, :hj4a,
                    :token, \'aktiv\'
                 )'
            );
            $insert->execute([
                'name' => $name,
                'tutorium' => $tutorium,
                'email' => $email,
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
            "SELECT k.*, h.name AS halbjahr_name, h.aktiv AS halbjahr_aktiv
             FROM sportkurse k
             INNER JOIN halbjahre h ON h.id = k.halbjahr_id
             WHERE k.id IN ($placeholders)
             FOR UPDATE"
        );
        $stmt->execute(array_values($ids));
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private function countPrimaryLocked(int $kursId): int
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
             INNER JOIN halbjahre h1 ON h1.id = k1.halbjahr_id
             INNER JOIN halbjahre h2 ON h2.id = k2.halbjahr_id
             INNER JOIN halbjahre h3 ON h3.id = k3.halbjahr_id
             INNER JOIN halbjahre h4 ON h4.id = k4.halbjahr_id
             WHERE a.token = :token'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function allActive(): array
    {
        return $this->pdo->query(
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
             WHERE a.status = 'aktiv'
             ORDER BY a.created_at DESC, a.name ASC"
        )->fetchAll();
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

    public function countActive(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM anmeldungen WHERE status = 'aktiv'"
        )->fetchColumn();
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

        $rawPlaceholders = [
            '{name}' => (string) $anmeldung['name'],
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
            . '<p>Hallo {name},</p>'
            . '<p>vielen Dank für Ihre Sportkurs-Wahl.</p>'
            . '<p><strong>Ihre Angaben:</strong><br>'
            . 'Name: {name}<br>'
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
            . '<p>Hallo {name},</p>'
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
