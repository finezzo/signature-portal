<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionStartMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionManager $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // /api/* uses per-tenant API keys, not sessions. Skipping start
        // here keeps the response free of the Set-Cookie header that
        // would otherwise be returned to the add-in on every compose.
        if (!str_starts_with($request->getUri()->getPath(), '/api/')) {
            $this->session->start();
        }
        return $handler->handle($request);
    }
}
