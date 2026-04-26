<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionManager $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->session->isAuthenticated()) {
            return (new ResponseFactory())
                ->createResponse(302)
                ->withHeader('Location', '/portal/login');
        }
        $request = $request->withAttribute('current_user', $this->session->currentUser());
        return $handler->handle($request);
    }
}
