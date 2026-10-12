<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;

final class ExportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $uploadDir
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function allActiveRows(?string $jahrgang = null): array
    {
        if ($jahrgang === null || $jahrgang === '') {
            $jahrgang = AnmeldungService::defaultSchoolYear();
        }
        $stmt = $this->pdo->prepare(
            "SELECT a.vorname, a.nachname, a.tutorium, a.email, a.jahrgang, a.created_at,
                    k1.name AS hj1_kurs, a1.name AS hj1_alt,
                    k2.name AS hj2_kurs, a2.name AS hj2_alt,
                    k3.name AS hj3_kurs, a3.name AS hj3_alt,
                    k4.name AS hj4_kurs, a4.name AS hj4_alt
             FROM anmeldungen a
             INNER JOIN sportkurse k1 ON k1.id = a.hj1_kurs_id
             INNER JOIN sportkurse a1 ON a1.id = a.hj1_alt_id
             INNER JOIN sportkurse k2 ON k2.id = a.hj2_kurs_id
             INNER JOIN sportkurse a2 ON a2.id = a.hj2_alt_id
             INNER JOIN sportkurse k3 ON k3.id = a.hj3_kurs_id
             INNER JOIN sportkurse a3 ON a3.id = a.hj3_alt_id
             INNER JOIN sportkurse k4 ON k4.id = a.hj4_kurs_id
             INNER JOIN sportkurse a4 ON a4.id = a.hj4_alt_id
             WHERE a.status = 'aktiv' AND a.jahrgang = :j
             ORDER BY a.nachname ASC, a.vorname ASC"
        );
        $stmt->execute(['j' => $jahrgang]);
        return $stmt->fetchAll();
    }

    public function csvAll(?string $jahrgang = null): string
    {
        $rows = $this->allActiveRows($jahrgang);
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [
            'Nachname', 'Vorname', 'Tutorium', 'E-Mail', 'Jahrgang',
            'HJ1 1. Wahl', 'HJ1 Alternative',
            'HJ2 1. Wahl', 'HJ2 Alternative',
            'HJ3 1. Wahl', 'HJ3 Alternative',
            'HJ4 1. Wahl', 'HJ4 Alternative',
            'Anmeldedatum',
        ], ';');
        foreach ($rows as $row) {
            fputcsv($fh, [
                $row['nachname'],
                $row['vorname'],
                $row['tutorium'],
                $row['email'],
                $row['jahrgang'],
                $row['hj1_kurs'],
                $row['hj1_alt'],
                $row['hj2_kurs'],
                $row['hj2_alt'],
                $row['hj3_kurs'],
                $row['hj3_alt'],
                $row['hj4_kurs'],
                $row['hj4_alt'],
                $row['created_at'],
            ], ';');
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $csv;
    }

    public function pdfAll(?string $logoFilename = null, ?string $jahrgang = null): string
    {
        $rows = $this->allActiveRows($jahrgang);
        if ($jahrgang === null || $jahrgang === '') {
            $jahrgang = AnmeldungService::defaultSchoolYear();
        }
        $datum = htmlspecialchars((new \DateTimeImmutable('now'))->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jahrgangHtml = htmlspecialchars($jahrgang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logoHtml = $this->logoImgHtml($logoFilename);

        $bodyRows = '';
        foreach ($rows as $row) {
            $bodyRows .= '<tr>'
                . '<td>' . htmlspecialchars((string) $row['nachname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $row['vorname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $row['tutorium'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $row['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $row['hj1_kurs'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br><small>Alt: '
                . htmlspecialchars((string) $row['hj1_alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></td>'
                . '<td>' . htmlspecialchars((string) $row['hj2_kurs'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br><small>Alt: '
                . htmlspecialchars((string) $row['hj2_alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></td>'
                . '<td>' . htmlspecialchars((string) $row['hj3_kurs'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br><small>Alt: '
                . htmlspecialchars((string) $row['hj3_alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></td>'
                . '<td>' . htmlspecialchars((string) $row['hj4_kurs'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br><small>Alt: '
                . htmlspecialchars((string) $row['hj4_alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></td>'
                . '</tr>';
        }
        if ($bodyRows === '') {
            $bodyRows = '<tr><td colspan="8">Keine aktiven Anmeldungen.</td></tr>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
    h1 { font-size: 16px; margin: 0 0 10px; }
    .meta { margin: 0 0 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #333; padding: 4px 5px; text-align: left; vertical-align: top; }
    th { background: #e8eee9; }
    small { color: #555; }
</style>
</head>
<body>
    {$logoHtml}
    <h1>Sportkurs-Anmeldungen</h1>
    <p class="meta">Jahrgang: {$jahrgangHtml} · Datum: {$datum}</p>
    <table>
        <thead>
            <tr>
                <th>Nachname</th><th>Vorname</th><th>Tutorium</th><th>E-Mail</th>
                <th>HJ1</th><th>HJ2</th><th>HJ3</th><th>HJ4</th>
            </tr>
        </thead>
        <tbody>{$bodyRows}</tbody>
    </table>
</body>
</html>
HTML;

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output() ?? '';
    }

    private function logoImgHtml(?string $logoFilename): string
    {
        if ($logoFilename === null || $logoFilename === '') {
            return '';
        }
        $filename = basename(str_replace('\\', '/', $logoFilename));
        $path = rtrim($this->uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return '';
        }
        $data = base64_encode((string) file_get_contents($path));
        $mime = mime_content_type($path) ?: 'image/png';
        return '<img src="data:' . $mime . ';base64,' . $data . '" style="max-height:48px;margin-bottom:8px;" alt="">';
    }
}
