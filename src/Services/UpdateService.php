<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Updates application files from a GitHub repository ZIP archive.
 * Preserves local config, uploads, vendor and install lock.
 */
final class UpdateService
{
    public const SUGGESTED_REPO = 'https://github.com/Merian-Schule-Berlin/Wahl-Sportkurse';
    public const DEFAULT_REF = 'main';

    /** Cache duration for background/login update checks (seconds). */
    private const CHECK_CACHE_TTL = 21600; // 6 Stunden

    /** @var list<string> Paths (relative to project root) that must never be overwritten */
    private const PRESERVE = [
        '.env',
        'vendor',
        'composer.phar',
        '.git',
        'storage',
        'public/uploads',
    ];

    public function __construct(
        private readonly string $rootPath,
        private readonly SettingsService $settings
    ) {
    }

    public function currentVersion(): string
    {
        $file = $this->rootPath . '/VERSION';
        if (!is_file($file)) {
            return 'unbekannt';
        }
        $v = trim((string) file_get_contents($file));
        return $v !== '' ? $v : 'unbekannt';
    }

    public function isConfigured(): bool
    {
        return trim($this->settings->get('update_repo_url')) !== '';
    }

    public function configuredRepoUrl(): string
    {
        return trim($this->settings->get('update_repo_url'));
    }

    public function configuredRef(): string
    {
        $saved = trim($this->settings->get('update_ref'));
        return $saved !== '' ? $saved : self::DEFAULT_REF;
    }

    /**
     * @return array{repo_url:string,ref:string,current:string,remote:?string,up_to_date:?bool,configured:bool,download_url:string,check_at:string}
     */
    public function status(bool $checkRemote = false): array
    {
        $configured = $this->isConfigured();
        $repoUrl = $configured ? $this->configuredRepoUrl() : '';
        $ref = $this->configuredRef();
        $current = $this->currentVersion();
        $remote = null;
        $upToDate = null;

        if ($configured && $checkRemote) {
            $result = $this->checkForUpdate(true);
            $remote = $result['remote'] ?? null;
            $upToDate = isset($result['available']) ? !$result['available'] : null;
        } elseif ($configured) {
            $cachedRemote = trim($this->settings->get('update_remote_version'));
            if ($cachedRemote !== '') {
                $remote = $cachedRemote;
                $upToDate = $this->settings->get('update_available') !== '1';
            }
        }

        return [
            'repo_url' => $repoUrl,
            'ref' => $ref,
            'current' => $current,
            'remote' => $remote,
            'up_to_date' => $upToDate,
            'configured' => $configured,
            'download_url' => $configured ? $this->zipDownloadUrl($repoUrl, $ref) : '',
            'check_at' => $this->settings->get('update_check_at'),
        ];
    }

    /**
     * Checks GitHub for a newer VERSION. Results are cached unless $force is true.
     *
     * @return array{available:bool,current:string,remote:string}|null null if not configured or check failed
     */
    public function checkForUpdate(bool $force = false): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $current = $this->currentVersion();

        if (!$force) {
            $cached = $this->cachedCheckResult($current);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $repoUrl = $this->configuredRepoUrl();
            $ref = $this->configuredRef();
            $remote = $this->fetchRemoteVersion($repoUrl, $ref);
            $available = version_compare(
                $this->normalizeVersion($current),
                $this->normalizeVersion($remote),
                '<'
            );

            $this->settings->setMany([
                'update_check_at' => date('c'),
                'update_remote_version' => $remote,
                'update_available' => $available ? '1' : '0',
                'update_checked_local_version' => $current,
            ]);

            return [
                'available' => $available,
                'current' => $current,
                'remote' => $remote,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Non-throwing helper for login/dashboard banners.
     *
     * @return array{available:bool,current:string,remote:string}|null
     */
    public function updateNotice(bool $forceRefresh = false): ?array
    {
        $result = $this->checkForUpdate($forceRefresh);
        if ($result === null || !$result['available']) {
            return null;
        }
        return $result;
    }

    /**
     * Download ZIP from the configured GitHub repository and apply over the installation.
     *
     * @return array{version:string,files:int,message:string}
     */
    public function applyConfigured(): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Bitte zuerst die Update-Quelle (GitHub-URL) speichern.');
        }
        return $this->apply($this->configuredRepoUrl(), $this->configuredRef());
    }

    /**
     * Download ZIP from GitHub (or direct ZIP URL) and apply over the installation.
     *
     * @return array{version:string,files:int,message:string}
     */
    public function apply(string $repoUrl, string $ref = self::DEFAULT_REF): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die PHP-Erweiterung ZipArchive ist nicht verfügbar.');
        }
        if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
            throw new RuntimeException('Weder cURL noch allow_url_fopen sind verfügbar – Download nicht möglich.');
        }

        $repoUrl = trim($repoUrl);
        $ref = $this->normalizeRef($ref);

        $candidates = [];
        if ($this->looksLikeDirectZipUrl($repoUrl)) {
            $candidates = [$repoUrl];
        } else {
            $displayRepo = $this->normalizeRepoUrl($repoUrl);
            $candidates = $this->zipDownloadCandidates($displayRepo, $ref);
        }

        $tmpBase = $this->rootPath . '/storage/tmp';
        if (!is_dir($tmpBase) && !mkdir($tmpBase, 0755, true) && !is_dir($tmpBase)) {
            throw new RuntimeException('Temporäres Verzeichnis storage/tmp konnte nicht angelegt werden.');
        }

        $token = bin2hex(random_bytes(8));
        $zipPath = $tmpBase . '/update-' . $token . '.zip';
        $extractDir = $tmpBase . '/update-' . $token;

        try {
            $this->downloadFirstAvailable($candidates, $zipPath);
            $sourceRoot = $this->extractZip($zipPath, $extractDir);
            $this->assertLooksLikeApp($sourceRoot);
            $files = $this->copyTree($sourceRoot, $this->rootPath);
            $this->clearTwigCache();
            $version = $this->currentVersion();

            $this->settings->setMany([
                'update_last_at' => date('Y-m-d H:i:s'),
                'update_last_version' => $version,
                'update_available' => '0',
                'update_remote_version' => $version,
                'update_check_at' => date('c'),
                'update_checked_local_version' => $version,
            ]);

            return [
                'version' => $version,
                'files' => $files,
                'message' => sprintf(
                    'Update auf Version %s abgeschlossen (%d Dateien aktualisiert). .env, Uploads, vendor und storage blieben erhalten.',
                    $version,
                    $files
                ),
            ];
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
            $this->removeDirectory($extractDir);
        }
    }

    public function savePreferences(string $repoUrl, string $ref): void
    {
        if ($this->looksLikeDirectZipUrl($repoUrl)) {
            throw new RuntimeException('Bitte eine GitHub-Repository-URL speichern, keinen direkten ZIP-Link.');
        }
        $normalizedUrl = $this->normalizeRepoUrl($repoUrl);
        $normalizedRef = $this->normalizeRef($ref);

        $unchanged = $normalizedUrl === $this->configuredRepoUrl()
            && $normalizedRef === $this->configuredRef();
        if ($unchanged) {
            // Bereits dauerhaft gespeichert – Cache nicht unnötig leeren.
            return;
        }

        $this->settings->setMany([
            'update_repo_url' => $normalizedUrl,
            'update_ref' => $normalizedRef,
            // Quelle geändert → Cache leeren
            'update_check_at' => '',
            'update_remote_version' => '',
            'update_available' => '0',
            'update_checked_local_version' => '',
        ]);
    }

    /**
     * @return array{available:bool,current:string,remote:string}|null
     */
    private function cachedCheckResult(string $current): ?array
    {
        $checkedAt = trim($this->settings->get('update_check_at'));
        $remote = trim($this->settings->get('update_remote_version'));
        $checkedLocal = trim($this->settings->get('update_checked_local_version'));
        if ($checkedAt === '' || $remote === '') {
            return null;
        }
        $ts = strtotime($checkedAt);
        if ($ts === false || (time() - $ts) > self::CHECK_CACHE_TTL) {
            return null;
        }
        // Local VERSION changed since last check (e.g. after manual deploy)
        if ($checkedLocal !== '' && $checkedLocal !== $current) {
            return null;
        }

        return [
            'available' => $this->settings->get('update_available') === '1',
            'current' => $current,
            'remote' => $remote,
        ];
    }

    private function normalizeRepoUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('Bitte eine GitHub-Repository-URL angeben.');
        }
        if (!preg_match('#^https://#i', $url)) {
            throw new RuntimeException('Die Repository-URL muss mit https:// beginnen.');
        }
        $parsed = $this->parseGitHubRepo($url);
        return 'https://github.com/' . $parsed['owner'] . '/' . $parsed['repo'];
    }

    private function normalizeRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return self::DEFAULT_REF;
        }
        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $ref)) {
            throw new RuntimeException('Ungültiger Branch/Tag-Name.');
        }
        return $ref;
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    private function looksLikeDirectZipUrl(string $url): bool
    {
        return (bool) preg_match('#^https://.+\.zip(\?.*)?$#i', trim($url));
    }

    /**
     * @return array{owner:string,repo:string}
     */
    private function parseGitHubRepo(string $url): array
    {
        $url = preg_replace('#\.git$#i', '', rtrim(trim($url), '/')) ?? $url;
        if (
            preg_match(
                '#^https://(?:www\.)?github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)(?:/.*)?$#i',
                $url,
                $m
            ) !== 1
        ) {
            throw new RuntimeException('Nur GitHub-Repository-URLs der Form https://github.com/owner/repo werden unterstützt.');
        }
        return ['owner' => $m[1], 'repo' => $m[2]];
    }

    private function zipDownloadUrl(string $repoUrl, string $ref): string
    {
        $parsed = $this->parseGitHubRepo($repoUrl);
        $owner = rawurlencode($parsed['owner']);
        $repo = rawurlencode($parsed['repo']);
        $refEnc = rawurlencode($ref);
        if (preg_match('#^v?\d+\.\d+#', $ref) === 1) {
            return "https://codeload.github.com/{$owner}/{$repo}/zip/refs/tags/{$refEnc}";
        }
        return "https://codeload.github.com/{$owner}/{$repo}/zip/refs/heads/{$refEnc}";
    }

    /**
     * @return list<string>
     */
    private function zipDownloadCandidates(string $repoUrl, string $ref): array
    {
        $parsed = $this->parseGitHubRepo($repoUrl);
        $owner = rawurlencode($parsed['owner']);
        $repo = rawurlencode($parsed['repo']);
        $refEnc = rawurlencode($ref);
        $heads = "https://codeload.github.com/{$owner}/{$repo}/zip/refs/heads/{$refEnc}";
        $tags = "https://codeload.github.com/{$owner}/{$repo}/zip/refs/tags/{$refEnc}";
        if (preg_match('#^v?\d+\.\d+#', $ref) === 1) {
            return [$tags, $heads];
        }
        return [$heads, $tags];
    }

    private function fetchRemoteVersion(string $repoUrl, string $ref): string
    {
        $parsed = $this->parseGitHubRepo($repoUrl);
        $rawUrl = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/VERSION',
            rawurlencode($parsed['owner']),
            rawurlencode($parsed['repo']),
            rawurlencode($ref)
        );
        $body = $this->httpGet($rawUrl, 8);
        $version = trim($body);
        if ($version === '' || strlen($version) > 64) {
            throw new RuntimeException('Remote-VERSION konnte nicht gelesen werden.');
        }
        return $version;
    }

    /**
     * @param list<string> $urls
     */
    private function downloadFirstAvailable(array $urls, string $destination): void
    {
        $lastError = null;
        foreach ($urls as $url) {
            try {
                $this->downloadFile($url, $destination);
                return;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }
        throw $lastError ?? new RuntimeException('Download fehlgeschlagen.');
    }

    private function downloadFile(string $url, string $destination): void
    {
        if (!$this->isAllowedDownloadHost($url)) {
            throw new RuntimeException('Download nur von github.com / codeload.github.com / raw.githubusercontent.com erlaubt.');
        }

        $data = $this->httpGet($url);
        if ($data === '' || strlen($data) < 100) {
            throw new RuntimeException('Download fehlgeschlagen oder Datei zu klein.');
        }
        if (file_put_contents($destination, $data) === false) {
            throw new RuntimeException('ZIP konnte nicht gespeichert werden.');
        }
    }

    private function isAllowedDownloadHost(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $allowed = [
            'github.com',
            'www.github.com',
            'codeload.github.com',
            'raw.githubusercontent.com',
            'objects.githubusercontent.com',
        ];
        return in_array($host, $allowed, true);
    }

    private function httpGet(string $url, int $timeoutSeconds = 120): string
    {
        $timeoutSeconds = max(3, $timeoutSeconds);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('cURL konnte nicht initialisiert werden.');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_USERAGENT => 'Wahl-Sportkurse-Updater',
                CURLOPT_HTTPHEADER => ['Accept: */*'],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException('Download fehlgeschlagen: ' . $err);
            }
            if ($code < 200 || $code >= 300) {
                throw new RuntimeException('Download fehlgeschlagen (HTTP ' . $code . '). Branch/Tag prüfen.');
            }
            return (string) $body;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Wahl-Sportkurse-Updater\r\nAccept: */*\r\n",
                'timeout' => $timeoutSeconds,
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('Download fehlgeschlagen.');
        }
        return $body;
    }

    private function extractZip(string $zipPath, string $extractDir): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('ZIP-Archiv konnte nicht geöffnet werden.');
        }
        if (!mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
            $zip->close();
            throw new RuntimeException('Entpack-Verzeichnis konnte nicht angelegt werden.');
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new RuntimeException('ZIP konnte nicht entpackt werden.');
        }
        $zip->close();

        $entries = array_values(array_filter(
            scandir($extractDir) ?: [],
            static fn (string $e): bool => $e !== '.' && $e !== '..'
        ));
        if (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0])) {
            return $extractDir . '/' . $entries[0];
        }
        return $extractDir;
    }

    private function assertLooksLikeApp(string $sourceRoot): void
    {
        $markers = ['composer.json', 'public/index.php', 'src', 'templates'];
        foreach ($markers as $marker) {
            $path = $sourceRoot . '/' . $marker;
            if (!file_exists($path)) {
                throw new RuntimeException(
                    'Das Archiv sieht nicht nach dieser Anwendung aus (fehlend: ' . $marker . ').'
                );
            }
        }
        $composer = (string) file_get_contents($sourceRoot . '/composer.json');
        if (!str_contains($composer, 'wahl-sportkurse/anmeldung')) {
            throw new RuntimeException('composer.json im Archiv gehört nicht zu wahl-sportkurse/anmeldung.');
        }
    }

    private function copyTree(string $from, string $to): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));
            if ($rel === '' || $this->shouldPreserve($rel)) {
                continue;
            }

            $target = $to . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $rel);
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new RuntimeException('Zielverzeichnis fehlt: ' . $rel);
            }
            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Datei konnte nicht kopiert werden: ' . $rel);
            }
            $count++;
        }

        return $count;
    }

    private function shouldPreserve(string $relativePath): bool
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        foreach (self::PRESERVE as $preserve) {
            if ($relativePath === $preserve || str_starts_with($relativePath, $preserve . '/')) {
                return true;
            }
        }
        return false;
    }

    private function clearTwigCache(): void
    {
        $this->removeDirectory($this->rootPath . '/storage/twig_cache');
    }

    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
