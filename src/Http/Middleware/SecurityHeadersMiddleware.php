<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Security headers for everything PHP renders. The Header directives in
 * public/.htaccess only reach static files on the IONOS setup, so the PHP
 * responses have to carry their own.
 *
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
        $response = $handler->handle($request)->withHeader('X-Content-Type-Options', 'nosniff');

        if (str_starts_with($request->getUri()->getPath(), '/portal')) {
            $response = $response
                ->withHeader('Content-Security-Policy', self::CSP)
                ->withHeader('X-Frame-Options', 'SAMEORIGIN')
                ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                // Browsers ignore this header on plain HTTP, so it needs no check
                // for the scheme, which is unreliable behind shared-host proxies.
                ->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        return $response;
    }
}
