<?php
declare(strict_types=1);

namespace App\Portal;

use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Self-service "my account" page — the logged-in user can update their
 * display name and password. Local-auth only; SSO users have password_hash
 * NULL and the password fields are hidden in the form.
 */
final class AccountController
{
    public function __construct(
        private readonly Twig $view,
        private readonly PDO $pdo,
        private readonly SessionManager $session,
        private readonly PasswordHasher $hasher,
    ) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->loadUser();
        if ($user === null) {
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        return $this->view->render($response, 'portal/account.twig', [
            'account' => $this->viewableAccount($user),
            'errors'  => [],
            'form'    => ['name' => (string) ($user['name'] ?? '')],
        ]);
    }

    /**
     * Project the user row down to the fields the view actually needs.
     * Avoids passing `password_hash`, `locked_until`, `failed_login_count`,
     * etc. into Twig context where a future template change could
     * accidentally render them.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function viewableAccount(array $user): array
    {
        return [
            'email'         => (string) ($user['email'] ?? ''),
            'role'          => (string) ($user['role'] ?? ''),
            'auth_provider' => (string) ($user['auth_provider'] ?? 'local'),
            'has_password'  => !empty($user['password_hash']),
        ];
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->loadUser();
        if ($user === null) {
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));

        $errors = [];
        if (mb_strlen($name) > 255) {
            $errors[] = 'Name is too long (max 255 chars).';
        }

        $newPass        = (string) ($body['new_password']      ?? '');
        $newPassConfirm = (string) ($body['new_password_confirm'] ?? '');
        $currentPass    = (string) ($body['current_password']  ?? '');

        $changingPassword = $newPass !== '' || $newPassConfirm !== '';
        $hashUpdate       = null;

        // Local-auth users have a password hash; SSO-only users don't and
        // can't set a password through this UI (Entra is the source of truth).
        $canChangePassword = !empty($user['password_hash']);

        if ($changingPassword) {
            if (!$canChangePassword) {
                $errors[] = 'This account uses Entra SSO — change the password in Microsoft Entra instead.';
            } else {
                if (mb_strlen($newPass) < 10) {
                    $errors[] = 'New password must be at least 10 characters.';
                }
                if ($newPass !== $newPassConfirm) {
                    $errors[] = 'New password and confirmation do not match.';
                }
                if (!$this->hasher->verify($currentPass, (string) $user['password_hash'])) {
                    $errors[] = 'Current password is incorrect.';
                }
                if ($errors === []) {
                    $hashUpdate = $this->hasher->hash($newPass);
                }
            }
        }

        if ($errors !== []) {
            return $this->view->render($response, 'portal/account.twig', [
                'account' => $this->viewableAccount($user),
                'errors'  => $errors,
                'form'    => ['name' => $name],
            ]);
        }

        $this->pdo->prepare(
            'UPDATE users SET
                name          = :name,
                password_hash = COALESCE(:hash, password_hash)
             WHERE id = :id'
        )->execute([
            ':name' => $name !== '' ? $name : null,
            ':hash' => $hashUpdate,
            ':id'   => (int) $user['id'],
        ]);

        // Refresh the session payload so the topbar shows the new name.
        $sessionUser = $this->session->currentUser() ?? [];
        $sessionUser['name'] = $name !== '' ? $name : null;
        $this->session->set('user', $sessionUser);

        $this->session->flash('success', 'Account updated.');
        return $response->withHeader('Location', '/portal/account')->withStatus(302);
    }

    /** @return array<string,mixed>|null */
    private function loadUser(): ?array
    {
        $session = $this->session->currentUser();
        if ($session === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => (int) $session['id']]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
