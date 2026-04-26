<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;

final class Authenticator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $session,
    ) {}

    public function attempt(string $email, string $password): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, email, password_hash, role, name
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => mb_strtolower(trim($email))]);
        $user = $stmt->fetch();

        if ($user === false || empty($user['password_hash'])) {
            return false;
        }
        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            return false;
        }

        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([
                ':h'  => $this->hasher->hash($password),
                ':id' => $user['id'],
            ]);
        }

        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute([':id' => $user['id']]);

        $this->session->regenerate();
        $this->session->set('user', [
            'id'        => (int) $user['id'],
            'email'     => (string) $user['email'],
            'name'      => $user['name'] !== null ? (string) $user['name'] : null,
            'role'      => (string) $user['role'],
            'tenant_id' => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
        ]);
        return true;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
