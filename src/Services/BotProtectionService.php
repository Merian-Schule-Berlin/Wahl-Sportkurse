<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Client + server verification for Google reCAPTCHA v2 and Cloudflare Turnstile.
 */
final class BotProtectionService
{
    public const PROVIDER_OFF = 'off';
    public const PROVIDER_RECAPTCHA = 'recaptcha';
    public const PROVIDER_TURNSTILE = 'turnstile';

    private const RECAPTCHA_VERIFY = 'https://www.google.com/recaptcha/api/siteverify';
    private const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function provider(): string
    {
        return $this->settings->botProtectionProvider();
    }

    public function isEnabled(): bool
    {
        $provider = $this->provider();
        if ($provider === self::PROVIDER_OFF) {
            return false;
        }
        return $this->siteKey() !== '' && $this->secretKey() !== '';
    }

    public function siteKey(): string
    {
        return trim($this->settings->get('bot_protection_site_key'));
    }

    public function secretKey(): string
    {
        return trim($this->settings->get('bot_protection_secret_key'));
    }

    /**
     * Config for Twig widgets / scripts.
     *
     * @return array{enabled:bool,provider:string,site_key:string,response_field:string,script_url:string}
     */
    public function widgetConfig(): array
    {
        $provider = $this->provider();
        $enabled = $this->isEnabled();

        return [
            'enabled' => $enabled,
            'provider' => $provider,
            'site_key' => $enabled ? $this->siteKey() : '',
            'response_field' => $provider === self::PROVIDER_TURNSTILE
                ? 'cf-turnstile-response'
                : 'g-recaptcha-response',
            'script_url' => match ($provider) {
                self::PROVIDER_TURNSTILE => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
                self::PROVIDER_RECAPTCHA => 'https://www.google.com/recaptcha/api.js',
                default => '',
            },
        ];
    }

    /**
     * @param array<string, mixed> $body Parsed request body
     */
    public function assertValid(array $body, ?string $remoteIp = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $field = $this->provider() === self::PROVIDER_TURNSTILE
            ? 'cf-turnstile-response'
            : 'g-recaptcha-response';
        $token = trim((string) ($body[$field] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Bitte den Bot-Schutz bestätigen.');
        }

        $ok = $this->verifyToken($token, $remoteIp);
        if (!$ok) {
            throw new RuntimeException('Bot-Schutz fehlgeschlagen. Bitte erneut versuchen.');
        }
    }

    public function verifyToken(string $token, ?string $remoteIp = null): bool
    {
        $secret = $this->secretKey();
        if ($secret === '' || $token === '') {
            return false;
        }

        $endpoint = $this->provider() === self::PROVIDER_TURNSTILE
            ? self::TURNSTILE_VERIFY
            : self::RECAPTCHA_VERIFY;

        $payload = [
            'secret' => $secret,
            'response' => $token,
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        try {
            $raw = $this->httpPostForm($endpoint, $payload);
        } catch (\Throwable) {
            throw new RuntimeException('Bot-Schutz konnte nicht geprüft werden. Bitte später erneut versuchen.');
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return false;
        }

        return !empty($json['success']);
    }

    /**
     * @param array<string, string> $fields
     */
    private function httpPostForm(string $url, array $fields): string
    {
        $body = http_build_query($fields);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('cURL konnte nicht initialisiert werden.');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                CURLOPT_USERAGENT => 'Wahl-Sportkurse-BotProtection',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($response === false) {
                throw new RuntimeException($err !== '' ? $err : 'HTTP-Fehler');
            }
            if ($code < 200 || $code >= 300) {
                throw new RuntimeException('HTTP ' . $code);
            }
            return (string) $response;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 10,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            throw new RuntimeException('HTTP-Anfrage fehlgeschlagen.');
        }
        return $response;
    }
}
