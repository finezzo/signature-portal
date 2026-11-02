<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionManager;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly PDO $pdo,
        private readonly bool $requireMfa = true,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->session->isAuthenticated()) {
            return $this->redirectToLogin();
        }

        // Kill sessions whose password changed after login (self-service
        // reset, admin reset): the epoch captured at login must still match
        // the DB. One indexed primary-key lookup per request — cheap, and
        // it also catches deleted users.
        $user = $this->session->currentUser();
        $stmt = $this->pdo->prepare('SELECT password_changed_at, role, tenant_id, mfa_enabled_at FROM users WHERE id = :id');
        $stmt->execute([':id' => (int) ($user['id'] ?? 0)]);
        $row = $stmt->fetch();

        $dbEpoch      = $row === false ? null : (string) ($row['password_changed_at'] ?? '');
        $sessionEpoch = (string) ($user['pw_epoch'] ?? '');

        // A session without a method predates two-factor sign-in, so what it
        // proved at login is unknown. One more sign-in settles that.
        if ($row === false || $dbEpoch !== $sessionEpoch || !isset($user['auth_method'])) {
            $this->session->destroy();
            return $this->redirectToLogin();
        }

        // Role and tenant decide what this request may do, so the database wins
        // over the copy captured at login: a demoted or moved user loses the old
        // rights on the next request, not whenever the session happens to end.
        $user['role']      = (string) $row['role'];
        $user['tenant_id'] = $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null;
        $this->session->set('user', $user);

        if ($this->mustEnrolFirst($user, $row, $request->getUri()->getPath())) {
            return (new ResponseFactory())
                ->createResponse(302)
                ->withHeader('Location', '/portal/account/mfa');
        }

        $request = $request->withAttribute('current_user', $user);
        return $handler->handle($request);
    }

    /**
     * A password sign-in without a second factor may only set one up. SSO sessions
     * are exempt: their second factor is Entra's business.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $row
     */
    private function mustEnrolFirst(array $user, array $row, string $path): bool
    {
        return $this->requireMfa
            && $user['auth_method'] === 'local'
            && empty($row['mfa_enabled_at'])
            && !str_starts_with($path, '/portal/account/mfa')
            && $path !== '/portal/logout';
    }

    private function redirectToLogin(): ResponseInterface
    {
        return (new ResponseFactory())
            ->createResponse(302)
            ->withHeader('Location', '/portal/login');
    }
}
