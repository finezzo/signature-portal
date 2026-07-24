<?php
declare(strict_types=1);

namespace App\Addin;

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
    public function __construct(private readonly SignatureService $service) {}

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

        $result = $this->service->resolve($req);

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
