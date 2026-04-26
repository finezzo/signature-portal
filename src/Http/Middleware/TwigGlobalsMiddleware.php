<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Csrf;
use App\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * Populates per-request Twig globals: csrf_token, current_user, flashes,
 * active_tenant_id. Set once here so individual controllers don't have to
 * thread these through view payloads.
 *
 * Twig environment is shared across requests, but PHP is request-isolated
 * (each FPM worker handles one request at a time) so per-request mutation
 * is safe.
 */
final class TwigGlobalsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Twig $twig,
        private readonly SessionManager $session,
        private readonly Csrf $csrf,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip on /api/* — those routes don't render Twig and there is no
        // session to read from.
        if (!str_starts_with($request->getUri()->getPath(), '/api/')) {
            $env = $this->twig->getEnvironment();
            $env->addGlobal('csrf_token',   $this->csrf->token());
            $env->addGlobal('current_user', $this->session->currentUser());
            $env->addGlobal('flashes',      $this->session->consumeFlashes());
        }
        return $handler->handle($request);
    }
}
