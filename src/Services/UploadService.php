<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class UploadService
{
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const ALLOWED_DOCS = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    public function __construct(private readonly string $uploadDir)
    {
        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden.');
        }
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     */
    public function storeImage(array $file, string $prefix = 'img'): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Keine Datei hochgeladen.');
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload fehlgeschlagen.');
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new RuntimeException('Datei ist zu groß (max. 5 MB).');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        if (!isset(self::ALLOWED[$mime])) {
            throw new RuntimeException('Nur JPEG, PNG, WebP oder GIF erlaubt.');
        }

        $ext = self::ALLOWED[$mime];
        $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = $this->uploadDir . '/' . $filename;
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : rename($file['tmp_name'], $target);
        if (!$moved) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }
        return $filename;
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     */
    public function storeDocument(array $file, string $prefix = 'doc'): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Keine Datei hochgeladen.');
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload fehlgeschlagen.');
        }
        if (($file['size'] ?? 0) > 15 * 1024 * 1024) {
            throw new RuntimeException('Datei ist zu groß (max. 15 MB).');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        $ext = self::ALLOWED_DOCS[$mime] ?? null;
        if ($ext === null) {
            $clientExt = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (in_array($clientExt, ['pdf', 'doc', 'docx'], true) && $mime === 'application/octet-stream') {
                $ext = $clientExt;
            }
        }
        if ($ext === null) {
            throw new RuntimeException('Nur PDF, DOC oder DOCX erlaubt.');
        }

        $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = $this->uploadDir . '/' . $filename;
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : rename($file['tmp_name'], $target);
        if (!$moved) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }
        return $filename;
    }

    public function delete(?string $filename): void
    {
        if ($filename === null || $filename === '') {
            return;
        }
        $path = $this->uploadDir . '/' . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
