<?php
declare(strict_types=1);

namespace App\Tenant;

use App\Graph\UserProfile;

/**
 * When someone sends from a shared mailbox, they have no Entra profile
 * under that address — only under their primary mailbox. This class
 * computes the "display email" that should appear in the signature for
 * that case.
 *
 * Default convention: `<initial>.<surname>@<from-domain>`, lowercase.
 * Examples (Anna Beispiel sending from `info@acme.com`):
 *   `a.beispiel@acme.com`
 *
 * Per-tenant overrides land in Phase 4 — for now this is the only
 * convention. Callers fall back to the primary user's mail address when
 * the convention can't be applied (missing surname or from-domain).
 */
final class DisplayEmailDeriver
{
    public function deriveForSharedMailbox(UserProfile $primary, string $fromDomain): string
    {
        $domain = mb_strtolower(trim($fromDomain));
        $surname = $primary->surname !== null ? mb_strtolower(trim($primary->surname)) : '';
        $given   = $primary->givenName !== null ? trim($primary->givenName) : '';

        if ($domain === '' || $surname === '' || $given === '') {
            // Fall back to the primary user's own mail rather than build a
            // half-formed alias. The signature looks slightly off but at
            // least the address is real.
            return $primary->mail ?? $primary->userPrincipalName;
        }

        $initial = mb_strtolower(mb_substr($given, 0, 1));
        $surname = preg_replace('/\s+/u', '-', $surname) ?? $surname;
        return "{$initial}.{$surname}@{$domain}";
    }
}
