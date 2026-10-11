<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class AuthService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function attempt(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash FROM admins WHERE username = :u LIMIT 1'
        );
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }

        $_SESSION['admin_id'] = (int) $row['id'];
        $_SESSION['admin_username'] = (string) $row['username'];

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_username']);
    }

    public function check(): bool
    {
        return isset($_SESSION['admin_id']);
    }

    public function id(): ?int
    {
        return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    }

    public function username(): ?string
    {
        return $_SESSION['admin_username'] ?? null;
    }

    public function canManageSettings(): bool
    {
        return $this->check();
    }

    /** @return list<array<string, mixed>> */
    public function listUsers(): array
    {
        return $this->pdo->query(
            'SELECT id, username, created_at FROM admins ORDER BY username ASC'
        )->fetchAll();
    }

    /**
     * @param array{username:string,password:string} $data
     */
    public function createUser(array $data): int
    {
        $username = trim($data['username']);
        $password = $data['password'];

        if ($username === '' || strlen($username) > 100) {
            throw new RuntimeException('Bitte einen gültigen Benutzernamen angeben.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Das Passwort muss mindestens 8 Zeichen haben.');
        }

        $exists = $this->pdo->prepare('SELECT id FROM admins WHERE username = :u');
        $exists->execute(['u' => $username]);
        if ($exists->fetch()) {
            throw new RuntimeException('Dieser Benutzername ist bereits vergeben.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO admins (username, password_hash) VALUES (:u, :p)'
        );
        $stmt->execute([
            'u' => $username,
            'p' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteUser(int $id): void
    {
        if ($this->id() === $id) {
            throw new RuntimeException('Sie können Ihren eigenen Account nicht löschen.');
        }

        $adminCount = (int) $this->pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($adminCount <= 1) {
            throw new RuntimeException('Der letzte Administrator kann nicht gelöscht werden.');
        }

        $this->pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $id]);
    }
}
