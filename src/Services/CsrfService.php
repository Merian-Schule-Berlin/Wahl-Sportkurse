<?php

declare(strict_types=1);

namespace App\Services;

final class CsrfService
{
    private const SESSION_KEY = '_csrf_token';

    public function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public function field(): string
    {
        $token = htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    public function validate(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';
        return is_string($sessionToken) && hash_equals($sessionToken, $token);
    }
}
