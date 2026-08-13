<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Content-Security-Policy for the portal UI (defence in depth on top of
 * HTML Purifier and the sandboxed preview iframes).
 *
 * 'unsafe-inline' for scripts/styles is a deliberate compromise: the Twig
 * templates use inline <script>/style blocks throughout, and nonce-ing them
 * all would touch every view for little gain. The policy still blocks the
 * important vector — loading script from anywhere except our own origin and
 * the TinyMCE CDN — plus object/base/frame-ancestor tricks.
 *
 * Scoped to /portal — the add-in files are static (served by Apache, see
 * public/addin/.htaccess) and /api/sig returns an HTML fragment for XHR
 * consumption where a CSP header would be meaningless.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const CSP = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "font-src 'self' data: https://cdn.jsdelivr.net; "
        . "img-src 'self' data: https:; "
        . "connect-src 'self' https://cdn.jsdelivr.net; "
        . "frame-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (str_starts_with($request->getUri()->getPath(), '/portal')) {
            $response = $response->withHeader('Content-Security-Policy', self::CSP);
        }
        return $response;
    }
}
