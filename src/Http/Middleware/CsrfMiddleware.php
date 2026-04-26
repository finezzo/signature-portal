<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Verifies the CSRF token on state-changing requests for the portal UI.
 *
 * Routes under /api/* are skipped — those use the per-tenant API key,
 * not session cookies, so CSRF is not applicable.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly Csrf $csrf) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldVerify($request)) {
            $body  = $request->getParsedBody();
            $token = is_array($body) ? ($body[Csrf::FIELD_NAME] ?? null) : null;
            $token ??= $request->getHeaderLine('X-CSRF-Token') ?: null;

            if (!$this->csrf->verify(is_string($token) ? $token : null)) {
                $resp = (new ResponseFactory())->createResponse(419);
                $resp->getBody()->write('CSRF token mismatch.');
                return $resp->withHeader('Content-Type', 'text/plain');
            }
        }

        $request = $request->withAttribute('csrf_token', $this->csrf->token());
        return $handler->handle($request);
    }

    private function shouldVerify(ServerRequestInterface $request): bool
    {
        if (!in_array($request->getMethod(), self::PROTECTED_METHODS, true)) {
            return false;
        }
        $path = $request->getUri()->getPath();
        return !str_starts_with($path, '/api/');
    }
}
