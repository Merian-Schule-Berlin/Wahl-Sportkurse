<?php

declare(strict_types=1);

namespace App\Services;

/**
 * File-based login throttle (IP). Survives session resets.
 */
final class LoginThrottleService
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 900;
    private const LOCKOUT_SECONDS = 900;

    public function __construct(private readonly string $storageDir)
    {
    }

    public function isLocked(string $ip): bool
    {
        return $this->remainingLockSeconds($ip) > 0;
    }

    public function remainingLockSeconds(string $ip): int
    {
        $state = $this->read($ip);
        $lockedUntil = (int) ($state['locked_until'] ?? 0);
        $remaining = $lockedUntil - time();
        return $remaining > 0 ? $remaining : 0;
    }

    public function recordFailure(string $ip): void
    {
        $now = time();
        $state = $this->read($ip);
        $attempts = array_values(array_filter(
            array_map('intval', $state['attempts'] ?? []),
            static fn (int $ts): bool => ($now - $ts) < self::WINDOW_SECONDS
        ));
        $attempts[] = $now;

        $lockedUntil = (int) ($state['locked_until'] ?? 0);
        if (count($attempts) >= self::MAX_ATTEMPTS) {
            $lockedUntil = $now + self::LOCKOUT_SECONDS;
            $attempts = [];
        }

        $this->write($ip, [
            'attempts' => $attempts,
            'locked_until' => $lockedUntil,
        ]);
    }

    public function clear(string $ip): void
    {
        $path = $this->pathFor($ip);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array{attempts?:list<int>,locked_until?:int} */
    private function read(string $ip): array
    {
        $path = $this->pathFor($ip);
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** @param array{attempts:list<int>,locked_until:int} $state */
    private function write(string $ip, array $state): void
    {
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
            return;
        }
        $path = $this->pathFor($ip);
        $tmp = $path . '.tmp';
        $json = json_encode($state);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        @rename($tmp, $path);
    }

    private function pathFor(string $ip): string
    {
        $key = hash('sha256', $ip !== '' ? $ip : 'unknown');
        return rtrim($this->storageDir, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
    }
}
