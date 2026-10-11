<?php

declare(strict_types=1);

namespace App\Services;

final class MailService
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(private array $settings)
    {
    }

    /**
     * @param list<array{filename:string,content:string,mime?:string}> $attachments
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        ?string $from = null,
        ?string $fromName = null,
        bool $isHtml = false,
        array $attachments = []
    ): bool {
        $from ??= (string) ($this->settings['mail']['from'] ?? '');
        $fromName ??= (string) ($this->settings['mail']['from_name'] ?? 'Wahl-Sportkurse');

        if ($from === '' || $to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $encodedFrom = sprintf('%s <%s>', $this->encodeHeader($fromName), $from);
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $encodedFrom,
            'Reply-To: ' . $from,
            'X-Mailer: PHP/' . PHP_VERSION,
        ];

        if ($attachments !== []) {
            $boundary = 'm_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $payload = $this->buildMixedPayload($boundary, $body, $isHtml, $attachments);
        } elseif ($isHtml) {
            $boundary = 'b_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $payload = $this->buildAlternativePayload($boundary, $body);
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $payload = $body;
        }

        return @mail(
            $to,
            $this->encodeHeader($subject),
            $payload,
            implode("\r\n", $headers)
        );
    }

    /**
     * Sends one mail per recipient (To = only that address).
     * Recipients never see other addresses.
     *
     * @param list<string> $recipients
     * @param list<array{filename:string,content:string,mime?:string}> $attachments
     * @return array{sent:int,failed:int}
     */
    public function sendIndividually(
        array $recipients,
        string $subject,
        string $body,
        ?string $from = null,
        ?string $fromName = null,
        bool $isHtml = false,
        array $attachments = []
    ): array {
        $unique = [];
        foreach ($recipients as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $unique[$email] = true;
            }
        }

        $sent = 0;
        $failed = 0;
        foreach (array_keys($unique) as $to) {
            if ($this->send($to, $subject, $body, $from, $fromName, $isHtml, $attachments)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function sendTest(string $to, string $from, string $fromName): bool
    {
        $body = "Dies ist eine Testmail der Wahl-Sportkurse-Anmeldung.\n\n"
            . "Wenn Sie diese Nachricht lesen, funktioniert der Versand.\n";
        return $this->send($to, 'Testmail – Wahl-Sportkurse', $body, $from, $fromName);
    }

    /**
     * @param list<array{filename:string,content:string,mime?:string}> $attachments
     */
    private function buildMixedPayload(string $boundary, string $body, bool $isHtml, array $attachments): string
    {
        $altBoundary = 'a_' . bin2hex(random_bytes(8));
        $payload = "--{$boundary}\r\n";

        if ($isHtml) {
            $payload .= 'Content-Type: multipart/alternative; boundary="' . $altBoundary . "\"\r\n\r\n"
                . $this->buildAlternativePayload($altBoundary, $body);
        } else {
            $payload .= "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $body . "\r\n";
        }

        foreach ($attachments as $attachment) {
            $filename = (string) ($attachment['filename'] ?? 'anhang.bin');
            $content = (string) ($attachment['content'] ?? '');
            $mime = (string) ($attachment['mime'] ?? 'application/octet-stream');
            $safeName = preg_replace('/[\r\n"\\\\]+/', '_', $filename) ?? 'anhang.bin';

            $payload .= "\r\n--{$boundary}\r\n"
                . 'Content-Type: ' . $mime . '; name="' . $safeName . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $safeName . "\"\r\n\r\n"
                . chunk_split(base64_encode($content));
        }

        $payload .= "--{$boundary}--\r\n";
        return $payload;
    }

    private function buildAlternativePayload(string $boundary, string $bodyHtml): string
    {
        $plain = $this->htmlToPlainText($bodyHtml);
        return "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $plain . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $this->wrapHtmlDocument($bodyHtml) . "\r\n\r\n"
            . "--{$boundary}--\r\n";
    }

    private function wrapHtmlDocument(string $bodyHtml): string
    {
        return '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>E-Mail</title></head><body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;'
            . 'font-size:16px;line-height:1.5;color:#222;">'
            . $bodyHtml
            . '</body></html>';
    }

    private function htmlToPlainText(string $html): string
    {
        $withBreaks = preg_replace(
            ['#<(br|BR)\s*/?>#', '#</(p|div|h[1-6]|li|tr)\s*>#i', '#<(li)\b[^>]*>#i'],
            ["\n", "\n", "• "],
            $html
        ) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text) . "\n";
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
