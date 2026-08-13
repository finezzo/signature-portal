<?php
declare(strict_types=1);

namespace App\Addin;

use App\Http\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public, key-authenticated endpoint the Outlook add-in calls at compose
 * time.
 *
 *   GET /api/sig
 *     ?tenant=<slug>
 *     &email=<from>            (required — the FROM address; may be shared)
 *     &primary=<primary>       (the user's primary mailbox; used as fallback
 *                               when FROM has no Entra profile)
 *     &recipients=<csv>        (optional — to/cc/bcc, comma-separated)
 *     &language=<bcp47>        (optional — overrides Graph preferredLanguage)
 *     &mailbox_type=shared|personal  (optional override; otherwise auto)
 *
 *   Auth: X-Sig-Key: <key>  (header only — no query-string fallback)
 *
 *   Responses:
 *     200 text/html with X-Sig-Shared: true|false  — render this signature
 *     204                                          — no rule matched; do nothing
 *     401                                          — bad tenant or key
 *     404                                          — sender/primary not in Graph
 *     502                                          — Graph upstream error
 *     500                                          — server-side mismatch (e.g. rule references missing template)
 */
final class SigController
{
    /**
     * Per-tenant ceiling: an org where everyone composes at once stays well
     * below this; a leaked key doing directory enumeration does not.
     * Per-IP failure ceiling: throttles key guessing without affecting
     * legitimate clients (which never produce auth failures).
     */
    private const TENANT_LIMIT_PER_MINUTE  = 120;
    private const FAILURE_LIMIT_PER_MINUTE = 15;

    public function __construct(
        private readonly SignatureService $service,
        private readonly RateLimiter $limiter,
    ) {}

    public function getSignature(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = $request->getQueryParams();

        // Key is accepted ONLY via the X-Sig-Key header. A `?key=` query
        // fallback was removed: query strings are written verbatim to Apache
        // access logs on shared hosting, which would leak the long-lived tenant
        // secret. The add-in (commands.js) already sends the header.
        $key = $request->getHeaderLine('X-Sig-Key');

        $req = new SignatureRequest(
            tenantSlug:   trim((string) ($q['tenant']  ?? '')),
            apiKey:       $key,
            fromEmail:    mb_strtolower(trim((string) ($q['email']   ?? ''))),
            primaryEmail: mb_strtolower(trim((string) ($q['primary'] ?? ''))),
            recipients:   $this->parseRecipients((string) ($q['recipients'] ?? '')),
            language:     $this->nullIfEmpty((string) ($q['language'] ?? '')),
            mailboxType:  $this->validateMailboxType((string) ($q['mailbox_type'] ?? '')),
        );

        // Minimal validation — return 400 for malformed input rather than
        // burning a Graph round-trip.
        if ($req->tenantSlug === '' || $req->apiKey === '' || !str_contains($req->fromEmail, '@')) {
            $response->getBody()->write('missing or malformed parameter (tenant, key, email)');
            return $response->withStatus(400)->withHeader('Content-Type', 'text/plain');
        }

        // Rate limits (before any DB/Graph work):
        //  - per client IP for auth FAILURES → slows down key guessing. The
        //    bucket is only incremented after an actual 401 (below); here we
        //    just refuse IPs that are already over the limit.
        //  - per tenant slug for everything → caps what a leaked key can
        //    exfiltrate per minute (directory enumeration).
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        if (!$this->limiter->check('sigfail:' . $ip, self::FAILURE_LIMIT_PER_MINUTE, 60)) {
            return $this->tooManyRequests($response);
        }
        if (!$this->limiter->hit('sig:' . $req->tenantSlug, self::TENANT_LIMIT_PER_MINUTE, 60)) {
            return $this->tooManyRequests($response);
        }

        $result = $this->service->resolve($req);

        if ($result->kind === SignatureResult::KIND_ERROR && $result->status === 401) {
            $this->limiter->hit('sigfail:' . $ip, self::FAILURE_LIMIT_PER_MINUTE, 60);
        }

        // Always set X-Sig-Shared so the add-in can show consistent UI.
        $response = $response->withHeader('X-Sig-Shared', $result->sharedMailbox ? 'true' : 'false');

        if ($result->kind === SignatureResult::KIND_ERROR) {
            $response->getBody()->write($result->error);
            return $response->withStatus($result->status)->withHeader('Content-Type', 'text/plain');
        }
        if ($result->kind === SignatureResult::KIND_NO_MATCH) {
            return $response->withStatus(204);
        }

        $response->getBody()->write($result->html);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function tooManyRequests(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('rate limit exceeded');
        return $response
            ->withStatus(429)
            ->withHeader('Retry-After', '60')
            ->withHeader('Content-Type', 'text/plain');
    }

    /** @return list<string> */
    private function parseRecipients(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && str_contains($p, '@')) {
                $out[] = mb_strtolower($p);
            }
        }
        return array_values($out);
    }

    private function nullIfEmpty(string $s): ?string
    {
        $s = trim($s);
        return $s === '' ? null : $s;
    }

    private function validateMailboxType(string $s): ?string
    {
        $s = trim($s);
        return in_array($s, ['personal', 'shared'], true) ? $s : null;
    }
}
