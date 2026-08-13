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
        $stmt = $this->pdo->prepare('SELECT password_changed_at FROM users WHERE id = :id');
        $stmt->execute([':id' => (int) ($user['id'] ?? 0)]);
        $row = $stmt->fetch();

        $dbEpoch      = $row === false ? null : (string) ($row['password_changed_at'] ?? '');
        $sessionEpoch = (string) ($user['pw_epoch'] ?? '');

        if ($row === false || $dbEpoch !== $sessionEpoch) {
            $this->session->destroy();
            return $this->redirectToLogin();
        }

        $request = $request->withAttribute('current_user', $user);
        return $handler->handle($request);
    }

    private function redirectToLogin(): ResponseInterface
    {
        return (new ResponseFactory())
            ->createResponse(302)
            ->withHeader('Location', '/portal/login');
    }
}
